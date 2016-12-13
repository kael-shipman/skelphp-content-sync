<?php

use PHPUnit\Framework\TestCase;

class ContentSynchronizerTest extends TestCase {
  public function testGetDataFromFile() {
    $lib = Env::getLib();

    // Env::getLib automatically adds a predictible set of content files to the pages directory.
    // The source data can be accessed for comparison via Env::getKnownContentData

    $data = $lib->getDataFromFile('/knownContent0.md');
    $expected = Env::getKnownContentData(0);
    $this->assertEquals($expected['title'], $data['title']);
    $this->assertEquals($expected['dateCreated'], $data['dateCreated']);
    $this->assertEquals($expected['imgPrefix'], $data['imgPrefix']);
    $this->assertEquals($expected['contentClass'], $data['contentClass']);
    $this->assertEquals(explode(', ',$expected['tags'])[0], $data['tags'][0]['tag']);
  }

  public function testGetObjectFromFile() {
    $lib = Env::getLib();
    $obj = $lib->getObjectFromFile('/knownContent0.md');
    $data = Env::getKnownContentData(0);
    $this->assertEquals($data['title'], $obj['title']);
    $this->assertEquals('/'.\Skel\Page::createSlug($data['title']), $obj['address']);
    $this->assertEquals($data['dateCreated'], $obj['dateCreated']->format('Y-m-d'));
    $this->assertTrue($obj['active']);
    $this->assertEquals($data['content'], $obj['content']);
    $this->assertTrue($obj instanceof \Skel\Post);
    $this->assertEquals(2, count($obj['tags']));
  }

  public function testHandlesParentCorrectly() {
    $lib = Env::getLib();
    $obj = $lib->getObjectFromFile('/knownContent3.md');
    $data = Env::getKnownContentData(3);
    $this->assertTrue(array_key_exists('parent', $data), "The chosen content should have a parent key for this test to be useful");
    $this->assertEquals($data['title'], $obj['title']);
    $this->assertEquals($data['parent'].'/'.\Skel\Page::createSlug($data['title']), $obj['address']);
  }

  public function testThrowsErrorOnUnknownClass() {
    $lib = Env::getLib();
    $unknownClass = array(
      'title' => 'Content with unknown class',
      'contentClass' => 'non-existent',
      'dateCreated' => '2016-12-10',
      'imgPrefix' => '2016-12-unknown-class',
      'content' => 'This content doesn\'t have a known class'
    );
    Env::addFileFromData('unknownClass.md', $unknownClass);

    try {
      $obj = $lib->getObjectFromFile('/unknownClass.md');
      $this->fail("Should have thrown an exception when trying to parse content with an unknown class");
    } catch (PHPUnit_Framework_AssertionFailedError $e) {
      throw $e;
    } catch (\Skel\UnknownContentClassException $e) {
      $this->assertTrue(true, "This is the expected exception.");
    }

    // Clean up
    Env::deleteFile('unknownClass.md');
  }

  public function testUpdateDbFromNewFile() {
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $data = Env::getKnownContentData(0);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    $this->assertEquals(null, $cms->getContentByAddress($addr), "Shouldn't have gotten anything back from the database yet, since we haven't run the file yet. If we're getting something back, then we're not properly cleaning up after tests");

    $lib->updateDbFromFile('/knownContent0.md');
    $obj = $cms->getContentByAddress($addr);

    $this->assertTrue($obj instanceof \Skel\Post, "Should have returned a Skel\Post object");
    $this->assertEquals($data['title'], $obj['title']);
  }

  public function testDoesNotInsertDuplicateEntryForSameFile() {
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $data = Env::getKnownContentData(0);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    // If not already in DB, add this content to the DB
    if ($cms->getContentByAddress($addr) === null) $cms->saveObject((new \Skel\Post())->updateFromUserInput($data));

    $dbObj = $cms->getContentByAddress($addr);
    $this->assertTrue($dbObj instanceof \Skel\Post, "Should have returned a previous entry in the db");

    // Now create a duplicate content file with a different name
    Env::addFileFromData('dupContent0.md', $data);
    try {
      $lib->updateDbFromFile('/dupContent0.md');
      $this->fail("Should have thrown an exception on attempt to edit same record from two different files");
    } catch (PHPUnit_Framework_AssertionFailedError $e) {
      throw $e;
    } catch (\Skel\InvalidContentFileException $e) {
      $this->assertTrue(true, "This is the correct behavior");
    }
  }

  public function testUpdateExistingRecord() {
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $data = Env::getKnownContentData(0);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    // If not already in DB, add this content to the DB via a ContentFile
    if (($dbObj = $cms->getContentByAddress($addr)) === null) {
      $lib->updateDbFromFile('/knownContent0.md');
      $dbObj = $cms->getContentByAddress($addr);
    }

    // Now get the data from file and verify that it's the same as the db
    $fileData = $lib->getDataFromFile('/knownContent0.md');
    $this->assertEquals($dbObj['title'], $fileData['title']);
    $this->assertEquals($dbObj['imgPrefix'], $fileData['imgPrefix']);

    // Now change the img prefix and save back to the file
    $fileData['imgPrefix'] = '2016-11-different-test';
    $fileData['tags'] = 'One Tag, Two Tags';
    Env::addFileFromData('/knownContent0.md', $fileData);

    // Now update from file and verify that it worked
    $lib->updateDbFromFile('/knownContent0.md');
    $obj = $cms->getContentByAddress($addr);

    $this->assertEquals($fileData['imgPrefix'], $obj['imgPrefix']);
    $this->assertEquals('One Tag', $obj['tags'][0]['tag']);
  }

  public function testUpdateFileFromDb() {
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $data = Env::getKnownContentData(0);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    // If not already in DB, add this content to the DB via a ContentFile
    if ($cms->getContentByAddress($addr) === null) $lib->updateDbFromFile('/knownContent0.md');

    // Verify that we have a ContentFile record in the db for this content
    $this->assertEquals(1, count(ContentSyncConfig::getInstance()->getDb()->getContentFileList()->filter('path', '/knownContent0.md')), "Looks like there's no ContentFile in the database for `knownContent0.md`. Something must be off...");

    $dbObj = $cms->getContentByAddress($addr);
    $this->assertTrue($dbObj instanceof \Skel\Post, "Should have returned a previous entry in the db");

    // Alter the object and resave it
    $dbObj['title'] = 'New test title 8000';
    $cms->saveObject($dbObj);

    // Now update the file from the database
    $lib->updateFileFromDb('/knownContent0.md');

    $fileObj = $lib->getObjectFromFile('/knownContent0.md');
    $this->assertEquals($dbObj['title'], $fileObj['title']);
    $this->assertEquals($dbObj['address'], $fileObj['address']);
    $this->assertEquals($dbObj['dateCreated']->format('Y-m-d'), $fileObj['dateCreated']->format('Y-m-d'));
  }

  public function testAutoRename() {
    // Should be able to rename a file and pass it to `updateDbFromFile` and have it correct the db records
    // Result should be no more record for old file, new record for new file, and updated content, if applicable
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $db = ContentSyncConfig::getInstance()->getDb();
    $data = Env::getKnownContentData(0);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    // If not already in DB, add this content to the DB via a ContentFile
    if ($cms->getContentByAddress($addr) === null) $lib->updateDbFromFile('/knownContent0.md');

    // Verify that we have a ContentFile record in the db for this content
    $this->assertEquals(1, count(ContentSyncConfig::getInstance()->getDb()->getContentFileList()->filter('path', '/knownContent0.md')), "Looks like there's no ContentFile in the database for `knownContent0.md`. Something must be off...");

    // Rename and add again
    Env::renameFile('/knownContent0.md', '/renamedContent0.md');
    $lib->updateDbFromFile('/renamedContent0.md');

    $fileList = $db->getContentFileList();
    $this->assertTrue(!$fileList->contains('path', '/knownContent0.md'), "The database should no longer contain a record for the old filename");
    $this->assertTrue($fileList->contains('path', '/renamedContent0.md'), "The database should now contain a record for the new filename");
  }

  public function testDeleteFile() {
    $lib = Env::getLib();
    $cms = ContentSyncConfig::getInstance()->getCms();
    $db = ContentSyncConfig::getInstance()->getDb();
    $data = Env::getKnownContentData(1);
    $addr = '/'.\Skel\Page::createSlug($data['title']);

    // If not already in DB, add this content to the DB via a ContentFile
    if (($dbObj = $cms->getContentByAddress($addr)) === null) {
      $lib->updateDbFromFile('/knownContent1.md');
      $dbObj = $cms->getContentByAddress($addr);
    }

    // Verify that we have a ContentFile record in the db for this content
    $this->assertEquals(1, count(ContentSyncConfig::getInstance()->getDb()->getContentFileList()->filter('path', '/knownContent1.md')), "Looks like there's no ContentFile in the database for `knownContent1.md`. Something must be off...");

    $lib->deleteFromDb('/knownContent1.md');

    $this->assertEquals(null, $cms->getContentById($dbObj['id']), "Should not be able to pull deleted content out of the db");
    $this->assertEquals(0, count(ContentSyncConfig::getInstance()->getDb()->getContentFileList()->filter('path', '/knownContent1.md')), "There should no longer be a contentFile record in the db.");
  }

  public function testSynchronizeFiles() {
    $lib = Env::getLib();
    $config = ContentSyncConfig::getInstance();
    $db = $config->getDb();
    $cms = $config->getCms();
    $lib->syncContent();

    $this->assertEquals(count($db->getContentFileList()), count($cms->getContentIndex()), "There should be the same number of entries in the database as there are files in the folder");

    $testData = Env::getKnownContentData(3);
    $addr = $testData['parent'].'/'.\Skel\Page::createSlug($testData['title']);
    $dbContent = $cms->getContentByAddress($addr);
    $this->assertTrue($dbContent instanceof \Skel\Page, "Should have gotten a Page instance from the db.");

    $contentFile = $db->getContentFileList()->filter('path', '/knownContent3.md')[0];
    $this->assertEquals($dbContent['id'], $contentFile['contentId'], "The known content file should be associated with the content");
  }
}

