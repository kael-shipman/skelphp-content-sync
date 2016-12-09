<?php
namespace Skel;

class ContentSynchronizer extends App {
  use CliTraits;
  protected $cms;
  protected $fileList;
  protected $processedFiles;





  // Execution Functions

  public function setCms(Interfaces\Cms $cms) {
    $this->cms = $cms;
    return $this;
  }

  public function getFileList(bool $force=false) {
    if ($force || !$this->fileList) $this->fileList = $this->db->getContentFileList();
    return $this->fileList;
  }

  public function run() {
    echo "\n\nWelcome to the Skel Content Synchronizer";

    $run = true;
    while ($run) {
      echo "\n\nPlease choose from the following options:";
      echo "\n  1) Synchronize the content database and the content files";
      echo "\n  0) Exit the app";

      $r = null;
      while ($r === null) {
        $n = trim(readline("\n\nSelection: "));

        if ($n == 0) throw new StopAppException("Application stopped by user");
        elseif ($n == 1) $r = $this->router->getPath('syncContent');
        else echo "\nSorry, that doesn't appear to be a valid selection. Try again.";
      }
      $run = $this->router->routeRequest(\Skel\Request::create($r), $this);
    }
    $this->stop();
  }

  public function stop() {
  }

  public function syncContent(array $vars=array()) {
    if (!$this->cms) throw new \RuntimeException("You must provide a valid Skel CMS object via the application's `setCms` method before synchronizing!");
    
    // Update the file list
    $this->getFileList(true);
    $this->processedFiles = new DataCollection();

    // Iterate through all files in content pages directory (recursively)
    $this->traverseDir($this->config->getContentPagesDir());

    // Now iterate through the rest of the files in the list and delete them from the DB
    foreach($this->fileList as $dbFile) {
      if ($this->processedFiles->indexOf($dbFile) === null) $this->deleteFromDb($dbFile['path']);
    }

    // Clear everything out
    $this->fileList = null;
    $this->processedFiles = null;
  }

  protected function traverseDir(string $dirname) {
    $dir = dir($dirname);
    while (($file = $dir->read()) !== false) {
      if ($this->isIgnored($dirname, $file)) continue;
      $path = "$dirname/$file";
      if (is_dir($path)) $this->traverseDir($path);
      else {
        // Get normalized file path for DB
        $dbPath = $this->getDbFilePath($path);
        // Filter DB objects to get the one associated with this path (should only be one entry, but we shouldn't assume....)
        $dbFile = $this->fileList->filter('path', $dbPath);

        // If the file is not found in the database
        if (count($dbFile) == 0) {
          // See if the file was renamed and if so, register that
          $filePrevPath = $this->getFilePreviousPath($dbPath);
          if ($filePrevPath) {
            $this->db->registerFileRename($filePrevPath, $dbPath);
            $this->getFileList(true);
          }

          // Now add or update content from file
          $this->updateDbFromFile($path);

        // If the file IS in the DB and is fresher than the db version, update db from file
        } elseif (filemtime($path) > $dbFile[0]['mtime']) $this->updateDbFromFile($path);

        // Otherwise, update the file from the DB, if applicable
        else $this->updateFileFromDb($path);
      }
    }
  }











  // Data Exchange Functions

  public function deleteFromDb(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    if ($contentFile) {
      $content = $this->cms->getContentById($contentFile['contentId']);
      $this->cms->deleteObject($content);
    }
  }

  public function updateDbFromFile(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    if ($contentFile) $dbContent = $this->cms->getContentById($contentFile['contentId']);
    else $contentFile = (new ContentFile())->set('path', $this->getDbFilePath($filepath));

    // If this contentFile hasn't already been scanned...
    if (!$dbContent) {
      // Create a new Content object from it
      $dbContent = $this->getObjectFromFile($filepath);

      // But check to see if it's a duplicate address
      $check = $this->cms->getContentByAddress($dbContent['address']);

      // If so, use the data object that's already in the DB and update its fields
      if ($check) {
        $data = $dbContent->getData();
        unset($data['id']);
        $check->updateFromUserInput($data);
        $check['tags'] = $dbContent['tags'];
        $dbContent = $check;
      }

    // If the content file HAS already been scanned, update the fields of its content object
    } else {
      $newData = $this->getDataFromFile($filepath);
      $dbContent->updateFromUserInput($newData);
      if (array_key_exists('tags', $newData)) $dbContent['tags'] = $newData['tags'];
    }

    // Save
    $this->cms->saveObject($dbContent);

    // Update and save the content file record
    $contentFile['contentId'] = $dbContent['id'];
    $contentFile['mtime'] = new DateTime((int)filemtime($this->getFullFilePath(string $filepath)));
    $this->db->saveObject($contentFile);
  }

  public function updateFileFromDb(string $filepath) {
    $contentFile = $this->getFileList()->filter('path', $this->getDbFilePath($filepath))[0];
    if (!$contentFile) throw new \RuntimeException("Something unexpected happened. `updateFileFromDb` should be called when a content file has already been scanned, but may not be up to date with the DB. This exception is being thrown because the given file couldn't be located in the DB....");

    $dbContent = $this->cms->getContentById($contentFile['contentId']);
    $fileObj = $this->getObjectFromFile($filepath);

    // Update file object from db object
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

    if ($changed) {
      $newFile = '';
      foreach($fileObj as $field => $val) {
        if ($fileObj->fieldSetBySystem($field) || $field == 'content') continue;
        $newFile .= "$field: $val\n";
      }
      $newFile .= "tags: ".implode(', ', $fileObj['tags']->getColumn('tag'))."\n";
      $newFile .= "\n".$fileObj['content'];

      file_put_contents($newFile, $this->getFullFilePath($filepath));
    }

    // Now update file mtime in db so we don't update again next time
    $contentFile['mtime'] = filemtime($this->getFullFilePath($filepath));
    $this->db->saveObject($contentFile);
  }








  // File Path Functions

  protected function getFullFilePath(string $filepath) {
    if (strpos($filepath, $this->config->contentPagesDir()) !== false) return $filepath;
    else return $this->config->contentPagesDir().$filepath;
  }

  protected function getDbFilePath(string $filepath) {
    if (strpos($filepath, $this->config->contentPagesDir()) !== false) return '/'.trim(str_replace($this->config->contentPagesDir(), '', $filepath),'/');
    else return '/'.trim($filepath, '/');
  }

  public function getFilePreviousPath(string $filepath) {
    $prevPath = null;
    $newFile = $this->getObjectFromFile($filepath);

    foreach ($this->getFileList() as $k => $file) {
      $contentFile = $this->cms->getContentById($file['contentId']);
      // Could go a lot deeper on this, but for now, just checking address and assuming
      // we didn't also change the content or properties
      if ($contentFile['address'] == $newFile['address']) return $file['path'];
    }
    return null;
  }







  // Low-level Content Data Functions

  public function getObjectFromFile(string $filepath) {
    $data = $this->getDataFromFile($filepath);
    $contentObject = $this->dressData($data);
    if ($contentObject['parent']) $contentObject['address'] = $contentObject['parent'].'/'.$contentObject::createSlug($contentObject['title']);
    return $contentObject;
  }

  public function getDataFromFile(string $filepath) {
    $file = file_get_contents($filepath);
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
      if (!$delim) throw InvalidContentFileException("This content file appears to have a malformed header. Can't find the colon delimiter in header #$i, '$prop'");
      $k = substr($prop,0,$delim);
      $v = substr($prop,$delim+1);
      if ($v == '') $v = null;
      else $data[$k] = $v;
    }

    $this->prepareObjectData($data);

    return $data;
  }

  public function getContentClasses() {
    return array('post' => 'Skel\Post', 'page' => 'Skel\Page');
  }

  protected function dressData(array $data) {
    $classes = $this->getContentClasses();
    if (!$data['contentClass'] || !array_key_exists($data['contentClass'], $classes)) throw new InvalidContentFileException("All content files must contain a `contentClass` header that contains one of the known content classes. (Hint: If you don't think you should be getting this error, make sure that you're overriding the `ContentSynchronizer::dressData` and adding content class maps for all the classes in your database.)");
    $obj = new $classes[$data['contentClass']]();
    $obj->updateFromUserInput($data);
    return $obj;
  }

  protected function prepareObjectData(array &$data) {
    if (array_key_exists('tags', $data)) {
      $tags = explode(',', str_replace(array("\t", "  ",", "), array(" ", " ", ","), trim($data['tags'], ",\t ")));
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


