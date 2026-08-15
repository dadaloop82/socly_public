# Risks and mitigations

Living risk register for Socly. Update when incidents or near-misses happen. See also [LESSONS_LEARNED.md](LESSONS_LEARNED.md).

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Unauthorized member delete / settings change | High | Med | Route permissions + CSRF; system admin not deletable; audit log |
| Credential stuffing / brute-force login | High | Med | Server-side rate limit (IP+email); strong passwords at install |
| Session fixation / theft | High | Low | Regenerate ID on login; httponly; Secure on HTTPS; SameSite |
| CSRF on state-changing forms | High | Med | Global CSRF middleware; token in forms + optional header |
| PII leakage via logs or error pages | High | Med | No POST/session dumps; generic errors when `APP_DEBUG=false` |
| Duplicate / conflicting member records | Med | Med | Duplicate check on identity fields; unique `member_number` |
| Invalid fiscal codes stored as truth | Med | Med | Algorithm validation; no silent birthplace defaults |
| Plugin with malicious code | High | Low | Trusted sources only; disable without data loss |
| Misconfigured document root exposing `.env` / storage | High | Med | `public/` only; `.htaccess` deny for sensitive patterns |
| Encrypted settings unreadable after key loss | High | Low | Backup `.env` `APP_KEY` with disaster recovery procedure |
| GDPR / retention mishandling | Med | Med | GDPR toggle; export; future retention tools on roadmap |
| Self-hosted DB loss | High | Med | Host backups; document restore under authenticated ops only |

## Explicitly rejected patterns

- Magic `user_id === 1` bypass
- Referer / query-string “authentication”
- Unauthenticated emergency DB import in core
- Secrets in documentation files
- CSRF only on the login form
