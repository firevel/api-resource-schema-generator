# API Resource Schema Generator

Generates JSON schema files for API resources using Firevel Generator.

## Usage

**Single resource:**
```bash
php artisan firevel:generate api-resource-schema --json=resource.json
```
Output: `schemas/api-resources/{name}/schema.json`

**Multiple resources:**
```bash
php artisan firevel:generate api-resource-schemas --json=resources.json
```
Output: `schemas/app.json`
