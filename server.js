var url = require('url');
var querystring = require('querystring');
var resolve = require('./resolve.js');

function server(req, res) {
  if (req.method === 'GET') {
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': '*'
    });
    var query = querystring.parse(url.parse(req.url).query);
    try {
      resolve([].concat(query.p || query['p[]']).splice(0, 25), (query.from || 'en').match(/\w{1,3}/)[0], (query.to || 'fa').match(/\w{1,3}/)[0]).then(function (x) {
        res.end(JSON.stringify(x));
      }, function (e) { res.end(JSON.stringify({ error: e.toString() })); });
    } catch (e) { res.end(JSON.stringify({ error: e.toString() })); };
  } else {
    res.writeHead(501);
    res.end();
  }
}

if (require.main === module) { // called directly, development
  require('http').createServer(server).listen(19876);
  console.log('Server running at http://127.0.0.1:19876/');
} else { // called through fcgi
  require('node-fastcgi').createServer(server).listen();
}
