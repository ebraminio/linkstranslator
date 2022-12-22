# linkstranslator
Backend for https://en.wikipedia.org/wiki/User:Ebrahim/ArticleTranslator.js, running at https://linkstranslator.toolforge.org/.

## System requirements
* Bare-bones PHP 7.3+, running behind a web server (e.g. Apache or lighttpd). No Composer or other fancy stuff.
* (optional) Have an INI file called `replica.my.cnf` containing the database user and password in the root directory (next to the `public_html` directory). This should be automatically provided on Toolforge; if you don’t have Toolforge access.
