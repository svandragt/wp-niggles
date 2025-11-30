# WP-Niggles
WordPress User Experience improvement plugin.

The scope if this plugin is limited to minor enhancements and tweaks, which create a saner and perhaps more opinionated set of defaults to the educated WordPress user.

## Features

* _Login Redirects_ - Redirects users after login based on their role. 
  * Network admins are redirected to the network admin dashboard.
  * Regular administrators are redirected to their site's admin dashboard. 
  * Editors to page list. 
  * Authors to a new page screen.
  * Contributors to a new post screen.
* _Super Admin Bump_ - WP-CLI commands to temporarily grant Super Admin role to a user, which is auto-revoked.
  * The number of minutes (5 to 60, default is 30) can be passed into the command. 
