PHP_ARG_ENABLE(tagcache, whether to enable tagcache extension,
[  --enable-tagcache       Enable tagcache high-performance client extension])

if test "$PHP_TAGCACHE" != "no"; then
  PHP_NEW_EXTENSION(tagcache, src/tagcache.c, $ext_shared)
fi
