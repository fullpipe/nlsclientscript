Fork of [nlsclientscript](https://github.com/nlac/nlsclientscript)

## Instalation
```json
  "require": {
    "nlac/nlsclientscript": "dev-master"
  },
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/fullpipe/nlsclientscript"
    }
  ]
  },
```

## Diffs:
* cs fixes
* removed `includePattern` option
* removed `excludePattern` option
* added `separate` option for client script package
  ```php
    'common' => array(
        'baseUrl' => '/',
        'css'     => ['css/main.css'],
        'js'      => array('js/main.js'),
        'separate' => true,
        'depends' => [
            'jquery',
            'cookie',
            'bootstrap',
            'angular',
        ],
    ),
  ```
  this package will be rendered separately always
