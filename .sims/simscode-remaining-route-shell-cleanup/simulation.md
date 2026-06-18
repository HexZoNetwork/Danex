# Simulation: remaining-route-shell-cleanup
Created: 2026-06-18T14:30:57.804Z

## Plan
Patch auth login panel, global navigation, console module CSS, and file manager module CSS to remove scan-line infinite animations, heavy glow, and gradient-heavy surfaces across all visible route shells. Keep static dark purple panels and restrained hover states.

## Files Affected
panel_overrides/resources/scripts/components/auth/LoginFormContainer.tsx
panel_overrides/resources/scripts/components/auth/LoginContainer.tsx
panel_overrides/resources/scripts/components/NavigationBar.tsx
panel_overrides/resources/scripts/components/server/console/style.module.css
panel_overrides/resources/scripts/components/server/files/style.module.css

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