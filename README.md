Shield
------

### Summary

PHP Authentication shield. It creates a simple shield for the site with HTTP
basic authentication. It hides the sites, if the user does not know a simple
username/password. It handles Drupal as a
["walled garden"](http://en.wikipedia.org/wiki/Walled_garden_%28technology%29).

This module helps you to protect your (dev) site with HTTP authentication.

### Basic configuration

To enable shield:

1. Enable the module
2. Go to the admin interface (admin/config/system/shield).
3. Check **Enable Shield** box, fill the **User** and **Password** fields.
4. Nothing else :)

### Exception options

There may be situation where shield should not interact with the default
process. For this reason, it is possible to configure some exceptions:

#### CLI
Allow the site to be accessed from the command line without Shield interfering.

#### IP
Allow specific IPs or range of IPs to access the site without being prompted
the basic auth. Be cautious using this option with reverse proxy caching in
use as the response may be cached and so accessible publicly.

#### HTTP method
Allow specific HTTP methods to not be protected by Shield. One example of use
is to allow OPTIONS requests in CORS context. See issue
[3085510](https://www.drupal.org/project/shield/issues/3085510) for more
details.

#### Domain
Allow specific domains to be publicly accessible (exclude mode) or protect only
specific domains (include mode). Typical use case for the exclude mode is to
allow the front-office domain so the back-office domain remains protected;
include mode is the other way round, for a site whose domains are public except
for a few. The `*` wildcard is supported. An empty list leaves every domain
protected whichever method is selected.

#### User agent
Allow requests whose user agent contains one of the configured strings, matched
case-insensitively. The typical use case is letting crawlers such as
`facebookexternalhit` reach the pages they need to scrape. Note that the user
agent is sent by the client and can be set to any value, so this is a
convenience for well behaved crawlers, not a security measure.

#### Paths
Allow specific path to be accessed without Shield being prompted (exclude mode)
or protect only specific path (include mode).

Shield runs before Drupal loads its module files, so paths are matched against
the URL as it was requested. Enter the path as a visitor types it, alias
included; internal paths such as `/node/12` only match if the request itself
uses them. An asterisk matches any number of characters and `<front>` matches
the site root. A language prefix configured in the URL language detection
settings is removed before matching, so `/user/login` also covers
`/nl/user/login`.

#### HTTP basic authentication
A browser or an HTTP client can only send one set of basic auth credentials,
so a REST or web service client that authenticates against Drupal with the
[HTTP Basic Authentication](https://www.drupal.org/docs/8/core/modules/basic_auth)
module has no way to also send the Shield credentials, and Shield rejects it
with a 401.

"Allow HTTP basic authentication" resolves that: a request that carries the
user name and password of an active Drupal account passes Shield, and
`basic_auth` then logs that account in as usual. The credentials are verified
by `basic_auth` itself, so core's flood control, blocked account handling and
password hashing all apply. The option only appears when the HTTP Basic
Authentication module is installed, and it is **disabled by default**.

Enabling it means every account on the site can open the shield, so the site
is only as protected as the weakest user password on it. Where the goal is to
expose a fixed set of service endpoints, allowing those paths (see above) is
the narrower option.

### Misc.

#### Debug header
With all the exception options, it may be unclear why basic auth is not
prompted. For this reason a debug header option has been added. It adds a
`X-Shield-Status` header in the response, and, whenever a policy governed the
request, an `X-Shield-Policy-Name` header carrying that policy's machine
name - useful when several policies with overlapping path conditions are
configured, to see which one actually decided the outcome.

`X-Shield-Status` options:
- `pending` (HTTP 401, basic auth is prompted)
- `authenticated` (HTTP 200, basic auth has been submitted)
- `disabled` (Enabled shield box is unchecked)
- `skipped (cli)` (HTTP 200, basic auth not prompted because it is a CLI
request
and "Allow command line access" is checked)
- `skipped (ip)` (HTTP 200, basic auth not prompted because the visitor's IP is
allowed)
- `skipped (http method)` (HTTP 200, basic auth not prompted because the HTTP
method of the request is allowed)
- `skipped (subrequest)` (HTTP 200, basic auth not prompted because it is
a subrequest and shield does handle it)
- `skipped (domain)` (HTTP 200, basic auth not prompted because the domain is
allowed)
- `skipped (path)` (HTTP 200, basic auth not prompted because the path is
allowed)
- `skipped (user agent)` (HTTP 200, basic auth not prompted because the user
agent of the request is allowed)
- `skipped (basic auth)` (HTTP 200, basic auth not prompted because the
request authenticated as a Drupal user and "Allow HTTP basic authentication"
is checked)

### Troubleshooting

#### The login prompt keeps reappearing with correct credentials

PHP only fills `PHP_AUTH_USER` and `PHP_AUTH_PW` when it runs as an Apache
module. On CGI, FastCGI and PHP-FPM setups - which most shared hosting uses -
the web server has to forward the `Authorization` header itself, and Shield
reads it back from `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`,
`REMOTE_USER` or `REDIRECT_REMOTE_USER`.

Drupal core's `.htaccess` already forwards the header, but if it has been
customized, or the site is not served by Apache, the header may never reach
PHP. On Apache, add one of these rewrite rules:

```apache
RewriteCond %{HTTP:Authorization} .+
RewriteRule .* - [E=HTTP_AUTHORIZATION:%0]
```

or, if the host does not allow setting `HTTP_AUTHORIZATION`:

```apache
RewriteRule .* - [E=REMOTE_USER:%{HTTP:Authorization}]
```

On nginx, make sure the `Authorization` header is passed to PHP-FPM:

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

See [issue 2902135](https://www.drupal.org/project/shield/issues/2902135).

#### Authentication logging
"Log successful authentications" records a log entry in the `shield` channel
every time a visitor passes the credentials prompt, containing the user name
they authenticated with, their client IP address and the requested path. It is
disabled by default.

Only successful authentications are logged. Requests that never reach the
prompt because an exception option let them through -- an allowed path, IP
address, domain or HTTP method, or command line access -- produce no log entry;
use the debug header above to diagnose those. Failed attempts are not logged
either, as an unauthenticated visitor's first request to any page is expected
to be rejected.

### Configuration via settings.php

The shield module can be configured via settings.php. This allows
it to be enabled on some environments and not on others using code.

#### Example with shield disabled:
To disable shield set **shield_enable** to **FALSE**.

```php
$config['shield.settings']['shield_enable'] = FALSE;
```
#### Example with shield enabled:
To enable shield set **user** and **pass** to real values.

```php
$config['shield.settings']['shield_enable'] = TRUE;
$config['shield.settings']['credentials']['shield']['user'] = 'username';
$config['shield.settings']['credentials']['shield']['pass'] = 'password';
$config['shield.settings']['print'] = 'Protected by a username and password.';
```

#### Example with several credential pairs:
The Shield credential provider accepts more than one username and password.
Separate the usernames with a pipe and list the matching passwords in the same
order. Any one of the pairs then unlocks the site. Because the pipe is the
separator, a username or password stored this way cannot contain one. This does
not apply to credentials held by the Key module, which are never split.

```php
$config['shield.settings']['credentials']['shield']['user'] = 'alice|bob';
$config['shield.settings']['credentials']['shield']['pass'] = 'first|second';
```

Shield has no notion of identity: every pair grants the same access, and the
site cannot tell which one was used. The reason to configure more than one is so
that a single pair can be withdrawn without rotating the password for everyone
else.

### Key module

The configuration storage supports storing the authentication in configuration
or in secure keys using http://www.drupal.org/project/key module. For the most
secure keys, use the key module 1.7 or higher which has a multi-value
user/password key for storing the user and password in a single key.

***See: <https://www.drupal.org/project/shield>***
