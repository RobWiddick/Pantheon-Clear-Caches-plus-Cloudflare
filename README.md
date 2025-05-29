# Pantheon: Clear All Edge Caches & Cloudflare Caches
For Pantheon + Cloudflare Proxy Stack: Clear ALL caches upon creating/editing any post.

**Note/Warning**: This plugin may not be suitable for every site. Global edge caching is a critical component that ensures fast delivery of pages. You may want to consider other options before resorting to this plugin.

Example use cases: 
* When using dynamic pages that pull in post contents from other posts, such as a leadership page that pulls in `members` posts, Pantheon's native functionality will not purge the leadership page when a member post is created. This results in an unexpected result to a content editor checking the front end.
* Site that have minimum caching periods may need to post updates frequently to keep people updated. For example, a sitewide banner configured in a global site config area may not result in pages being updated in a way that Pantheon or Cloudflare can catch, therefore resulting in the banner not showing in a timely manner.

## Installation Instructions
1. Place mu-clear-cloudflare-cache.php into `/code/wp-content/mu-plugins/` and commit, move up to `LIVE` environment
2. Place `cloudflare_cache_config.json` in the `LIVE` environment file path `/files/private/` at step 10 below

## Setup Plugin
1. Visit https://dash.cloudflare.com/profile/api-tokens to generate a token
2. Create a token -> custom token
3. Enter a recognizable name
4. Select Permission: Zone: Cache Purge: Purge
5. Select Zone Resources: Include: Specific Zone: yourdomain.com
6. Continue to Summary and Create Token. 
7. Save token to cloudflare_cache_config.json `auth_token` value
8. Go to the zone (domain) landing page in Cloudflare and get your Zone ID (in right-hand sidebar)
9. Save zone id to cloudflare_cache_config.json `zone_id` value
10. Move the cloudflare_cache_config.json file to your `LIVE` Pantheon environment path: `/files/private/`

## Testing
You can check if cache purging is working by creating or updating a WP post. 

Visit your Cloudflare Account -> Manage Account -> Audit Log and check for Cloudflare cache purge event.

## Contributing
Feel free to make updates. I initially created this plugin to be as simple and straightforward as possible. All contributions require a pull request and use cases.

## Support / Requests
You may post requests under the Issues tab and we'll try to answer as we can.