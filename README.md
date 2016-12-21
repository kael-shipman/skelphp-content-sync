# SkelPHP ContentSync

*NOTE: The Skel framework is an __experimental__ web applications framework that I've created as an exercise in various systems design concepts. While I do intend to use it regularly on personal projects, it was not necessarily intended to be a "production" framework, since I don't ever plan on providing extensive technical support (though I do plan on providing extensive documentation). It should be considered a thought experiment and it should be used at your own risk. Read more about its conceptual foundations at [my website](https://colors.kaelshipman.me/about/this-website).*

`ContentSync` is a library for synchronizing Skel `Page` and `Post` content between a Skel `Cms` and a set of filesystem files. Its primary method is `syncContent`, which recurses through the directory specified in the config object provided to the constructor, updating the database with file changes and the files with database changes. You can optionally turn off database-to-file sync by passing `false` to the `syncContent` method.

There are a few other methods that may prove useful, including `getDataFromFile` and `getObjectFromFile`.

## Installation

Eventually, this package is intended to be loaded as a composer package. For now, though, because this is still in very active development, I currently use it via a git submodule:

```bash
cd ~/my-website
git submodule add git@github.com:kael-shipman/skelphp-content-sync.git app/dev-src/skelphp/content-sync
```

This allows me to develop it together with the website I'm building with it. For more on the (somewhat awkward and complex) concept of git submodules, see [this page](https://git-scm.com/book/en/v2/Git-Tools-Submodules).

