<?php
namespace Skel;

class ContentSynchronizer extends App {
  use CliTrait;








  // Execution Functions

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
    $this->lib->syncContent();
  }
}


