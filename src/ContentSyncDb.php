<?php
namespace Skel;

class ContentSyncDb extends Db implements Interfaces\ContentSyncDb {
  const VERSION = 1;
  const SCHEMA_NAME = "ContentSyncDb";

  public function __construct(Interfaces\DbConfig $config) {
    parent::__construct($config);
    try {
      $this->verifyEnvironment();
    } catch (\PDOException $e) {
      throw new InadequateDatabaseSchemaException("Your database doesn't appear to implement the basic (or standard) \Skel\Cms database schema. Perhaps you need to run your app (thus creating and populating these tables) before running the content synchronizer?");
    }
  }

  protected function verifyEnvironment() {
    $this->db->query('SELECT * FROM "content" JOIN "contentTags" ON ("content"."id" = "contentId") JOIN "tags" ON ("tagId" = "tags"."id") LIMIT 1');
  }

  protected function upgradeDatabase(int $targ, int $from) {
    if ($from < 1 && $targ >= 1) {
      $this->db->exec('CREATE TABLE "contentFiles" ("id" INTEGER PRIMARY KEY, "path" TEXT NOT NULL, "mtime" INTEGER NOT NULL, "contentId" INTEGER NOT NULL)');
    }
  }

  protected function downgradeDatabase(int $targ, int $from) {
  }





  public function getContentFileList() {
    $list = $this->db->query('SELECT * FROM "contentFiles" ORDER BY path ASC, mtime DESC');
    $list = $list->fetchAll(\PDO::FETCH_ASSOC);
    foreach($list as $k => $data) $list[$k] = ContentFile::restoreFromData($data);
    return new DataCollection($list);
  }

  public function registerFileRename(string $prevPath, string $newPath) {
    $stm = $this->db->prepare('UPDATE "contentFiles" SET "path" = ? WHERE "path" = ?');
    $stm->execute(array($newPath, $prevPath));
  }

  public function filePathIsUnique(Interfaces\ContentFile $file) {
    $stm = $this->db->prepare('SELECT * FROM "contentFiles" WHERE "path" = ? and "id" != ?');
    $stm->execute(array($file['path'], $file['id']));
    return count($stm->fetchAll()) == 0;
  }

  public function fileContentIdIsUnique(Interfaces\ContentFile $file) {
    $stm = $this->db->prepare('SELECT * FROM "contentFiles" WHERE "contentId" = ? and "id" != ?');
    $stm->execute(array($file['contentId'], $file['id']));
    return count($stm->fetchAll()) == 0;
  }
}
