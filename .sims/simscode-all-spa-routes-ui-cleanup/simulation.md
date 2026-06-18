# Simulation: all-spa-routes-ui-cleanup
Created: 2026-06-18T14:26:14.893Z

## Plan
Patch shared SPA GlobalStylesheet and button styles so all Artisan-discovered SPA routes share the cleaned dark-purple design: /, /account, /server/*, /auth/*, tables, forms, route panels, and buttons. Remove cyan/blue secondary accents, noisy gradients, heavy glows, and use restrained transform/opacity motion with reduced-motion support.

## Files Affected
panel_overrides/resources/scripts/assets/css/GlobalStylesheet.ts
panel_overrides/resources/scripts/components/elements/button/style.module.css
panel_overrides/resources/views/templates/wrapper.blade.php

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