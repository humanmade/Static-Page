## Static Page Generator for WordPress

Export / save static copies of all your WordPress URLs.

This plugin is very specific in what it offers, that is - saving (almost) all URLs that can be publicly viewed
on a WordPress site to another directory. This can be used in conjunction with different Stream Wrappers to
upload to locations on S3, FTP etc.

The plugin essentials works like so:

1. Get all the URLs that exist on the site via `get_posts`, `get_terms` etc etc.
1. Generate the public markup from each URL.
1. Perform any replacements on the content such as transforming URLs.
1. Save the pages to a directory, preserving the original path.

This can be triggered via WP CLI Commands, or via cron when content on the WordPress site is updated.

### WP CLI Commands

**Get the output of a single page, for testing**

```bash
wp static-page output http://wordpress.dev/ [--replace-from=<from>] [--replace-to=<to>]
```

**List all URLs that Static Page knows about**

```bash
wp static-page urls
```

**Save the site to static files**

```bash
wp static-page save [--replace-from=<from>] [--replace-to=<to>]
```

### Configuration

Static Page is meant to be require little configuration, in the case than you _do_ need to configure this, you can use
the following hooks:

**Add / remove URLs that are generate**

```php
add_filter( 'static_page_site_urls', function ( $urls ) {
	$urls[] = site_url( '/my-hidden-url/' );
	return $urls;
});
```

**Make custom URL replacements on the contents of pages**

```php
add_filter( 'static_page_replace_urls_in_content', function ( $page_markup ) {
	return str_replace( site_url(), 'https://my-cdn.example.com/', $page_markup );
});
```
