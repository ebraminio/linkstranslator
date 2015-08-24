var url = require('url');
var querystring = require('querystring');
var resolve = require('./resolve.js');

var NodeCache = require("node-cache");
var caches = {};
var enableCache = true;

function server(req, res) {
  var prepareRequest;
  if (req.method === 'GET') {
    prepareRequest = Promise.resolve(url.parse(req.url).query);
  } else if (req.method === 'POST') {
    prepareRequest = new Promise(function (resolve) {
      var body = [];
      req.on('data', function (data) { body.push(data); });
      req.on('end', function () { resolve(body.join('')); });
    });
  } else {
    res.writeHead(501);
    res.end();
    return;
  }

  prepareRequest.then(function (request) {
    var query = querystring.parse(request);

    var from = (query.from || 'enwiki').toLowerCase().match(/[a-z_]{1,20}/)[0];
    if (from.indexOf("wiki") === -1) { from = from + 'wiki'; }
    var to = (query.to || 'fawiki').toLowerCase().match(/[a-z_]{1,20}/)[0];
    if (to.indexOf("wiki") === -1) { to = to + 'wiki'; }

    var pages = [].concat(query.p || query['p[]'] || []);

    var cached = {};
    if (enableCache) {
      var cache = caches[from + '@' + to];
      if (!cache) {
        cache = new NodeCache({ stdTTL: 600, checkperiod: 320 });
        caches[from + '@' + to] = cache;
      }

      cached = cache.get(pages);
      pages = pages.filter(function (page) { return !cached[page]; });
    }

    return resolve(pages, from, to).then(function (result) {
      if (enableCache) {
        for (var key in result) {
          cache.set(key, result[key]);
        }
        for (var key in cached) {
          result[key] = cached[key];
        }
      }
      return JSON.stringify(result);
    });
  }).then(function (result) {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*'
    });
    res.end(result);
  }, function (error) {
    res.writeHead(500);
    res.end(JSON.stringify({ error: error.toString() }));
  });
}

if (process.env.FCGI_MODE) { // called through fcgi
  require('node-fastcgi').createServer(server).listen();
} else { // called directly, development
  var port = process.argv[2] || 19876;
  require('http').createServer(server).listen(port);
  console.log('Server running at http://127.0.0.1:' + port + '/');
}
