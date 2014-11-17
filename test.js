var assert = require('assert');
var resolve = require('./resolve.js');
var passed = 0;

resolve(['سیب'], 'fawiki', 'enwiki').then(function (result) {
  assert.equal(result['سیب'], 'Apple');
  passed++;
});

resolve(['apple'], 'enwiki', 'fawiki').then(function (result) {
  assert.equal(result['apple'], 'سیب');
  passed++;
});

process.on('exit', function () {
  console.log('Assetions passed: ', passed);
});
