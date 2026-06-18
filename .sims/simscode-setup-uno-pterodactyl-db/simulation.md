# Simulation: setup-uno-pterodactyl-db
Created: 2026-06-18T14:39:55.998Z

## Plan
Patch setup.sh install_uno_minigame so it copies repo UNO files including keys.php, reads Pterodactyl .env DB credentials, creates/updates UNO tables in the existing Pterodactyl database, and no longer requires uno_online_db.sql or creates minigames.uno.database config defaults. Remove config merge block that recreates minigames.uno.database.

## Files Affected
setup.sh

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