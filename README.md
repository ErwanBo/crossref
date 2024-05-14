# OMP Crossref Plugin

## Introduction

This plugin registers DOIs for monographs and chapters via the DOI provider Crossref.

## Current Schema

The current schema version is compliant with Crossref specifications.

## Available Languages

Translations available in: German

## Installation

```bash
OMP=/path/to/OMP_INSTALLATION
cd $OMP/plugins/importexport
git clone https://github.com/PAYS-upcite/crossref
```

## Crossref Setup

1. **Navigate to** `{OMP_SERVER}/index.php/{MY_PRESS}/management/importexport/plugin/CrossrefExportPlugin`
2. **Use Crossref as DOI provider:**
   - Crossref URL: Use the test or production URL
   - Username: Your Crossref username
   - Password: Your Crossref password
   - **For testing only**: Use the Crossref test prefix for DOI registration. Please remember to remove this option for production.
   - Test registry: (For testing only), provided by Crossref
   - Test URL: (For testing only) Production URL for overwriting XML entries

## Usage

- **DOI Registration**: The plugin allows registering DOIs for monographs and chapters via the OMP management interface.

## Credits

### Main Developer and Designer

- https://github.com/withanage
- https://github.com/ajnyga
  
### Contributors

- PAYS-upcite
