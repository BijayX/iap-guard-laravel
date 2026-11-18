# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.x (latest minor) | Yes |

## Reporting a vulnerability

Please do not report security issues in public GitHub issues or pull requests.

Use GitHub's private vulnerability reporting ("Report a vulnerability" under the repository's
Security tab). Include:

- a description of the issue and its impact,
- steps or code to reproduce,
- affected versions and the store involved (Apple, Google or both).

You will receive an acknowledgement within 72 hours. Confirmed issues are fixed in a patch
release and disclosed after users have had a reasonable time to upgrade.

## Scope

In scope:

- anything that lets a forged, tampered, foreign-app or replayed purchase be accepted;
- anything that lets one app user obtain or keep another user's purchase;
- webhook authentication (App Store JWS, Pub/Sub OIDC / verification token);
- leaks of private keys, access tokens, JWTs, the account-token secret or user ids.

Out of scope: vulnerabilities in Apple's or Google's services (report those to them), and
applications that do not follow the security checklist in the README.
