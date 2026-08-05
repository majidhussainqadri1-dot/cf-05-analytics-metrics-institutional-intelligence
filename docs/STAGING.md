# Staging Acceptance Checklist

- fresh activation and upgrade without fatal errors;
- all declared tables physically present;
- runtime remains disabled by default;
- no secrets in code, options export or logs;
- contract immutability and unknown-version quarantine;
- HMAC, timestamp, replay and service-allowlist tests;
- seeded password/OTP/key/clinical/message/payment corpus absent downstream;
- duplicate, delayed, reordered and late events do not double count;
- minimum-cohort and differencing defenses;
- role/capability/BOLA/IDOR tests for all admin and REST surfaces;
- accessibility, keyboard, RTL/LTR, 200%/400% zoom and mobile tests;
- retention, export expiry, deletion propagation, restore and rollback evidence;
- File 00/20/23/24/25/26 contract compatibility;
- two fresh review-and-fix rounds with zero known unresolved critical/high defects;
- Founder acceptance before production activation.
