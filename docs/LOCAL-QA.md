# Local QA Receipt

Command:

```bash
bash scripts/qa.sh
```

Result on 05 August 2026 (Asia/Karachi):

- All PHP files passed syntax validation.
- 8 executable contract/privacy/lifecycle fixtures passed.
- `MANIFEST.json`, both JSON schemas and `composer.json` parsed successfully.
- Repository secret-pattern scan passed.

Exact GitHub-head CI remains a separate gate until the workflow runs on the committed branch.
