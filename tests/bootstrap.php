<?php

$path = '.';

require_once $path.'/vendor/autoload.php';

class ContentSyncConfig implements \Skel\Interfaces\DbConfig, \Skel\Interfaces\ContentSyncConfig {
  const PROF_TEST = 1;
  protected $vals = array();
  protected static $instance;

  protected function __construct() { }

  public function checkConfig() { return true; }
  public function dump() { throw new \RuntimeException("`dump` not implemented"); }
  public function get(string $key) { return $this->vals[$k]; }
  public function getExecutionProfile() { return static::PROF_TEST; }
  public function getDbContentRoot() { return __DIR__.'/content'; }
  public function getDbPdo() {
    if (!array_key_exists('pdo', $this->vals)) {
      exec('rm "'.__DIR__.'"/test.sqlite3');
      $this->vals['pdo'] = new PDO('sqlite:'.__DIR__.'/test.sqlite3');
      $this->vals['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $this->vals['pdo'];
  }
  public function getContentPagesDir() { return __DIR__.'/content/pages'; }
  public function set(string $k, $val) { 
    $this->vals[$k] = $val;
  }

  public function getCms() {
    if (!array_key_exists('cms', $this->vals)) $this->vals['cms'] = new \Skel\Cms($this);
    return $this->vals['cms'];
  }
  public function getDb() {
    if (!array_key_exists('db', $this->vals)) $this->vals['db'] = new \Skel\ContentSyncDb($this);
    return $this->vals['db'];
  }

  public static function getInstance() {
    if (!static::$instance) static::$instance = new static();
    return static::$instance;
  }
}






class Env {
  protected static $lib;
  protected static $knownContentAdded;

  protected function __construct() { }

  public static function getLib() {
    if (!static::$lib) {
      $config = ContentSyncConfig::getInstance();
      $cms = $config->getCms();
      $db = $config->getDb();
      static::$lib = new \Skel\ContentSynchronizerLib($config, $db, $cms);

      static::clearTestContentFiles();
      static::addAllKnownContent();
    }

    return static::$lib;
  }

  public static function addAllKnownContent() {
    if (!static::$knownContentAdded) {
      foreach(static::getKnownContentData() as $k => $content) static::addFileFromData('knownContent'.$k.'.md', $content);
      static::$knownContentAdded = true;
    }
  }

  public static function addFileFromData(string $filename, array $data) {
    $str = '';
    foreach($data as $field => $v) {
      if ($field == 'content') continue;
      $str .= "$field: $v\n";
    }
    $str .= "\n$data[content]";
    file_put_contents(ContentSyncConfig::getInstance()->getContentPagesDir().'/'.$filename, $str);
  }

  public static function deleteFile(string $filename) {
    unlink(ContentSyncConfig::getInstance()->getContentPagesDir().'/'.$filename);
  }

  public static function renameFile(string $old, string $new) {
    rename(
      ContentSyncConfig::getInstance()->getContentPagesDir().$old,
      ContentSyncConfig::getInstance()->getContentPagesDir().$new
    );
  }

  public static function getKnownContentData(int $key=null) {
    $c = array(
      array(
        'title' => 'New Content 1',
        'contentClass' => 'post',
        'dateCreated' => '2016-12-05',
        'imgPrefix' => '2016-12-new-content-1',
        'content' => 'My new test content',
        'tags' => 'Five Tag, Six Tag',
      ),
      array(
        'title' => 'New Content 2',
        'contentClass' => 'post',
        'dateCreated' => '2016-12-06',
        'imgPrefix' => '2016-12-new-content-2',
        'content' => 'My second new test content',
        'tags' => 'Five Tag, Six Tag',
      ),
      array(
        'title' => 'New Content 3',
        'contentClass' => 'post',
        'dateCreated' => '2016-11-13',
        'imgPrefix' => '2016-11-new-content-3',
        'content' => 'My new test content predated',
        'tags' => 'Five Tag, Six Tag',
      ),
      array(
        'title' => 'New Content with parent',
        'contentClass' => 'post',
        'dateCreated' => '2016-12-10',
        'imgPrefix' => '2016-12-content-with-parent',
        'content' => 'My new test content with a parent',
        'parent' => '/writings',
        'tags' => 'One Tag, Profound Realizations',
      ),
      array(
        'title' => 'New Content 8',
        'contentClass' => 'page',
        'dateCreated' => '2016-12-09',
        'imgPrefix' => '2016-12-new-page',
        'content' => 'This is a page instead of a post',
      ),
    );

    if ($key === null) return $c;
    else return $c[$key];
  }

  public static function clearTestContentFiles() {
    exec('rm -R "'.ContentSyncConfig::getInstance()->getContentPagesDir().'"/*');
  }
}

