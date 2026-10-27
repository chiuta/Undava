/* fff-pwa v1 · generat de fff-kit/pwa/pwa.mjs · fără cereri externe, fără importScripts */
'use strict';
var SLUG = "undava", VERSION = "2.1.0";
var PREFIX = 'fff-' + SLUG + '-', CACHE = PREFIX + VERSION;   // versiunea nu conține '-', deci sufixul de după ultimul '-' e versiunea
var SHELL = ['./manifest.webmanifest', './icon-192.png', './icon-512.png', './icon-maskable-512.png'];

self.addEventListener('install', function (e) {
  e.waitUntil((async function () {
    var c = await caches.open(CACHE);
    var r = await fetch('./index.html', {cache: 'reload'});
    if (!r.ok) throw new Error('index.html ' + r.status);
    await c.put('./index.html', r.clone());
    await c.put('./', r);
    await Promise.all(SHELL.map(function (u) { return c.add(new Request(u, {cache: 'reload'})).catch(function () {}); }));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', function (e) {
  e.waitUntil((async function () {
    var keys = await caches.keys();
    await Promise.all(keys.filter(function (k) {
      var i = k.lastIndexOf('-');
      return i >= 0 && k.slice(0, i + 1) === PREFIX && k !== CACHE;   // doar cache-urile acestei aplicații
    }).map(function (k) { return caches.delete(k); }));
    await self.clients.claim();
  })());
});

// Cache-first cu revalidare în fundal (stale-while-revalidate) pentru resursele de aceeași origine.
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET' || r.headers.has('range')) return;
  var u = new URL(r.url);
  if (u.origin !== self.location.origin || /\/sw\.js$/.test(u.pathname)) return;
  var nav = r.mode === 'navigate';
  var key = nav ? new Request(u.origin + u.pathname) : r;
  function update() {
    return fetch(nav ? u.href : r, {cache: 'no-cache'}).then(function (res) {
      if (res && res.status === 200 && res.type === 'basic' && !res.redirected) {
        var cp = res.clone();
        return caches.open(CACHE).then(function (c) { return c.put(key, cp); }).then(function () { return res; });
      }
      return res;
    });
  }
  e.respondWith(caches.open(CACHE).then(function (c) { return c.match(key); }).then(function (hit) {
    if (hit) { e.waitUntil(update().catch(function () {})); return hit; }
    return update().catch(function () {
      if (nav && /(\/|index\.html)$/.test(u.pathname)) return caches.open(CACHE).then(function (c) { return c.match('./index.html'); });
      return Response.error();
    });
  }));
});
