<?php
namespace Skel;

class ContentSynchronizerLib {
  use ObservableTrait;

  protected $db;
  protected $cms;
  protected $config;
  protected $fileList;


  public function __construct(Interfaces\ContentSyncConfig $config, Interfaces\ContentSyncDb $db, Interfaces\Cms $cms) {
    $this->db = $db;
    $this->cms = $cms;
    $this->config = $config;
    $this->notifyListeners('Create');
  }

  // Execution Functions

  public function getFileList(bool $force=false) {
    if ($force || !$this->fileList) $this->fileList = $this->db->getContentFileList();
    return $this->fileList;
  }

  public function syncContent(bool $doDbToFile=true) {
    // Iterate through all files in content pages directory and update records
    foreach($this->filesInDir($this->config->getContentPagesDir()) as $f) {
      if ($this->isIgnored($f[0], $f[1])) continue;
      $path = "$f[0]/$f[1]";
      // Get normalized file path for DB
      $dbPath = $this->getDbFilePath($path);
      // Filter DB objects to get the one associated with this path (should only be one entry, but we shouldn't assume....)
      $dbFile = $this->getFileList()->filter('path', $dbPath);

      $this->notifyListeners('BeforeProcessFile', array($path, $dbPath, $dbFile));

      // If the file is not found in the database or the db is out of date, update
      if (count($dbFile) == 0 || filemtime($path) > $dbFile[0]['mtime']) $this->updateDbFromFile($path);

      // Otherwise, update the file from the DB, if applicable
      elseif ($doDbToFile) $this->updateFileFromDb($path);

      $this->notifyListeners('AfterProcessFile', array($path, $dbPath, $dbFile));
    }

    // Now iterate through the files in the db cache and delete if nonexistent
    foreach($this->getFileList(true) as $dbFile) {
      if (!file_exists($this->getFullFilePath($dbFile['path']))) {
        $this->notifyListeners('BeforeDeleteNonexistentDbFileRecord', array($dbFile['path']));
        $this->deleteFromDb($dbFile['path']);
        $this->notifyListeners('AfterDeleteNonexistentDbFileRecord', array($dbFile['path']));
      }
    }

    // Clear everything out
    $this->fileList = null;

    $this->notifyListeners('AfterSyncContent');
  }

  protected function filesInDir(string $dirname) {
    $files = array();
    $dir = dir($dirname);
    while (($file = $dir->read()) !== false) {
      if (substr($file, 0, 1) == '.') continue;
      $path = "$dirname/$file";
      if (is_dir($path)) $files = array_merge($files, $this->filesInDir($path));
      else {
        $files[] = array($dirname, $file);
      }
    }
    return $files;
  }











  // Data Exchange Functions

  public function deleteFromDb(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    if (!$contentFile) return;

    $content = $this->cms->getContentById($contentFile['contentId']);
    $this->notifyListeners('BeforeDeleteContent', array($filepath, $content));
    $this->cms->deleteObject($content);
    $this->notifyListeners('AfterDeleteContent', array($filepath, $content));
    $this->notifyListeners('BeforeDeleteContentFile', array($filepath, $contentFile));
    $this->db->deleteObject($contentFile);
    $this->notifyListeners('AfterDeleteContentFile', array($filepath, $contentFile));
    $this->fileList->remove($contentFile);
  }

  public function updateDbFromFile(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    $this->notifyListeners('BeforeUpdateDbFromFile', array($filepath, $contentFile));

    // If the contentFile is already registered, get the content associated with it
    if ($contentFile) {
      $dbContent = $this->cms->getContentById($contentFile['contentId']);
      $dbContent->updateFromUserInput($this->getDataFromFile($filepath));

    // Otherwise...
    } else {
      $fileObj = $this->getObjectFromFile($filepath);

      // First check to see if this file represents content that's already in the db
      $dbContent = $this->cms->getContentByAddress($fileObj['address']);

      // If the content in this file already exists in the db....
      if ($dbContent) {
        // If it's already being managed by another content file....
        foreach($this->getFileList()->filter('contentId', (int)$dbContent['id']) as $dbFile) {
          // If that file still exists, throw an exception. Can't have two files managing one content
          if (file_exists($this->getFullFilePath($dbFile['path']))) throw new InvalidContentFileException("It appears as though content at $check[address] is already being managed by the file `$cf[path]`. The file `$dbFile[path]` is a duplicate and should be removed to avoid confusion.");

          // Otherwise...
          else {
            // If we haven't already chosen a ContentFile to rep this content, choose this one
            if (!$contentFile) {
              $contentFile = $dbFile;
              $contentFile['path'] = $this->getDbFilePath($filepath);

            // else delete it (this is in case the db is way screwed up and there's more than one duplicate)
            } else {
              $this->db->deleteObject($dbFile);
              $this->fileList->remove($dbFile);
            }
          }
        }

        // Update from file data
        $dbContent->updateFromUserInput($this->getDataFromFile($filepath));

      // If the content in this file isn't already in the Db, consider this a totally new entry
      } else {
        $contentFile = (new ContentFile())->set('path', $this->getDbFilePath($filepath));
        $this->fileList[] = $contentFile;
        $dbContent = $fileObj;
      }
    }

    // At this point, all we need to do is save
    $this->notifyListeners('BeforeSaveContent', array($filepath, $dbContent));
    $this->cms->saveObject($dbContent);
    $this->notifyListeners('AfterSaveContent', array($filepath, $dbContent));

    // Update and save the content file record
    $contentFile['contentId'] = $dbContent['id'];
    $contentFile['mtime'] = new \DateTime('@'.((int)filemtime($this->getFullFilePath($filepath))));
    $this->db->saveObject($contentFile);

    $this->notifyListeners('AfterUpdateDbFromFile', array($filepath, $dbContent));
  }

  public function updateFileFromDb(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    if (!$contentFile) throw new \RuntimeException("Something unexpected happened. `updateFileFromDb` should be called when a content file has already been scanned, but may not be up to date with the DB. This exception is being thrown because the given file couldn't be located in the DB....");

    $dbContent = $this->cms->getContentById($contentFile['contentId']);
    $fileObj = $this->getObjectFromFile($filepath);

    // Update file object from db object
    $this->notifyListeners('BeforeCompareDbToFile', array($filepath, $contentFile, $dbContent, $fileObj));
    $changed = false;
    foreach($dbContent as $field => $val) {
      if ($dbContent->fieldSetBySystem($field) || $val == $fileObj[$field]) continue;
      $changed = true;
      $fileObj[$field] = $val;
    }
    if ($fileObj['tags'] != $dbContent['tags']) {
      $fileObj['tags'] = $dbContent['tags'];
      $changed = true;
    }
    $this->notifyListeners('AfterCompareDbToFile', array($filepath, $contentFile, $dbContent, $fileObj));

    if ($changed) {
      $this->notifyListeners('BeforeWriteChangesToFile', array($filepath, $contentFile, $dbContent, $fileObj));
      $newFile = '';
      foreach($fileObj as $field => $val) {
        if ($fileObj->fieldSetBySystem($field) || $field == 'content' || $val instanceof Interfaces\DataCollection) continue;
        $newFile .= "$field: ".$fileObj->getRaw($field)."\n";
      }
      if ($fileObj['tags']) $newFile .= "tags: ".implode(', ', $fileObj['tags']->getColumn('tag'))."\n";
      $newFile .= "\n".$fileObj['content'];

      file_put_contents($this->getFullFilePath($filepath), $newFile);
      $contentFile['mtime'] = new \DateTime('@'.((int)filemtime($this->getFullFilePath($filepath))));
      $this->db->saveObject($contentFile);
      $this->notifyListeners('AfterWriteChangesToFile', array($filepath, $contentFile, $dbContent, $fileObj));
    }

    $this->notifyListeners('AfterUpdateFileFromDb', array($filepath, $contentFile));
  }








  // File Path Functions

  protected function getFullFilePath(string $filepath) {
    if (strpos($filepath, $this->config->getContentPagesDir()) !== false) return $filepath;
    else return $this->config->getContentPagesDir().$filepath;
  }

  protected function getDbFilePath(string $filepath) {
    if (strpos($filepath, $this->config->getContentPagesDir()) !== false) return '/'.trim(str_replace($this->config->getContentPagesDir(), '', $filepath),'/');
    else return '/'.trim($filepath, '/');
  }







  // Low-level Content Data Functions

  public function getObjectFromFile(string $filepath) {
    $data = $this->getDataFromFile($filepath);
    try { $contentObject = $this->dressData($data); }
    catch (UnknownContentClassException $e) {
      if (!is_array($e->extra)) $e->extra = array();
      $e->extra['dbFilepath'] = $this->getDbFilePath($filepath);
      $e->extra['fullFilepath'] = $this->getFullFilePath($filepath);
      throw $e;
    }
    return $contentObject;
  }

  public function getDataFromFile(string $filepath) {
    $file = file_get_contents($this->getFullFilePath($filepath));
    $delim = strpos($file, "\n\n");
    if (!$delim) $delim = strlen($file);

    $data = array();
    $extras = array();
    $headers = substr($file, 0, $delim);
    $data['content'] = substr($file, $delim+2);
    if (!$data['content']) $data['content'] = null;

    $headers = explode("\n", $headers);
    foreach($headers as $i => $prop) {
      $delim = strpos($prop, ':');
      // Note: This should be converted to a log entry instead of thrown as an exception
      if (!$delim) throw new InvalidContentFileException("This content file appears to have a malformed header. Can't find the colon delimiter in header #$i, '".substr($prop,0,30).(strlen($prop) > 30 ? '...' : '')."'");
      $k = substr($prop,0,$delim);
      $v = trim(substr($prop,$delim+1));
      if ($v == '') $v = null;
      $data[$k] = $v;
    }

    $this->prepareObjectData($data);

    return $data;
  }

  protected function dressData(array $data) {
    $classes = $this->cms->getContentClasses();
    if (!$data['contentClass'] || !array_key_exists($data['contentClass'], $classes)) {
      $e = new UnknownContentClassException("All content files must contain a `contentClass` header that contains one of the known content classes. The contentClass header for this file is `$data[contentClass]`. (Hint: If you don't think you should be getting this error, make sure that you're overriding the `ContentSynchronizerLib::dressData` and adding content class maps for all the classes in your database.)");
      $e->extra = array('contentClass' => $data['contentClass']);
      throw $e;
    }
    $obj = new $classes[$data['contentClass']]();
    $obj->updateFromUserInput($data);
    if (array_key_exists('parent', $data) && $obj->fieldSetBySystem('address')) $obj->set('address', $data['parent'].$obj['address'], true);
    $obj->setDb($this->cms);
    return $obj;
  }

  protected function prepareObjectData(array &$data) {
    if (array_key_exists('tags', $data)) {
      $tags = preg_split('/,\s*/', trim($data['tags'], ",\t "));
      $data['tags'] = $this->cms->getOrAddTagsByName($tags);
    }
  }







  // Extra internal functions

  protected function isIgnored(string $dirname, string $filename) {
    if (substr($filename, 0, 1) == '.') return true;
    elseif ($filename == 'README.md' || $filename == 'LICENSE') return true;
    else return false;
  }
}



