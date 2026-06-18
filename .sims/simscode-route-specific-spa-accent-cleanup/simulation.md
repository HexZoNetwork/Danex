# Simulation: route-specific-spa-accent-cleanup
Created: 2026-06-18T14:28:33.621Z

## Plan
Patch route-specific React/CSS components found by search: SubNavigation, DanexCPage, PublicChatPanel, MiniGamesPage, FileEditContainer notice, CodemirrorEditor, and console ANSI cyan mapping where UI colors leak cyan/blue. Keep behavior intact and only change visual tokens/styles.

## Files Affected
panel_overrides/resources/scripts/components/elements/SubNavigation.tsx
panel_overrides/resources/scripts/components/dashboard/DanexCPage.tsx
panel_overrides/resources/scripts/components/dashboard/chat/PublicChatPanel.tsx
panel_overrides/resources/scripts/components/dashboard/MiniGamesPage.tsx
panel_overrides/resources/scripts/components/server/files/FileEditContainer.tsx
panel_overrides/resources/scripts/components/elements/CodemirrorEditor.tsx
panel_overrides/resources/scripts/components/server/console/Console.tsx

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