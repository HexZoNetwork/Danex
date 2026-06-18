# Simulation: restore-auth-core-view
Created: 2026-06-18T15:21:28.272Z

## Plan
Add resources/views/templates/auth/core.blade.php to repo override and live /var/www/pterodactyl, extending templates/wrapper with the SPA app mount. Then clear compiled views/cache with artisan.

## Files Affected
panel_overrides/resources/views/templates/auth/core.blade.php
/var/www/pterodactyl/resources/views/templates/auth/core.blade.php

## Status: PENDING REVIEW
Review the plan and files above. Check for:
- Edge cases and error states
- Breaking changes to other files
- Missing imports or dependencies
- Type mismatches
- Security implications

## Result
- [ ] Simulation reviewed
- [ ] Edge cases handled
- [ ] No breaking changes
- [ ] Ready to execute