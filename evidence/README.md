# Evidence Policy

This directory is **not** a per-task archive.

Implementation history and task-level evidence are authoritative in:

- Git commits;
- GitHub Issues and Task Contracts;
- Pull Requests, reviews, and merge records;
- GitHub Actions workflow runs and artifacts.

Do not add `evidence/<phase>/<task>.md`, per-increment traceability files, handoff snapshots, test-count snapshots, or copied CI logs.

Repository evidence is reserved for **release-candidate and final-release records** that must remain available after normal CI artifact retention. A release record should contain only durable, sanitized metadata such as:

- release/RC version and exact commit;
- applicable requirement/release boundary;
- exact accepted workflow run(s);
- artifact/checksum/signature identifiers;
- target environment class (not credentials);
- high-level verification result;
- known limitations or owner-accepted residual risk.

Never store secrets, customer data, raw provider payloads, subscription material, private backup contents, or unrestricted operational logs here.

Historical task evidence that previously lived in this directory remains available through Git history.