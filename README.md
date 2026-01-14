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

## File Exists Options

When output file exists, you'll be prompted:

- `override` (default) - Merge with existing file
- `overwrite` - Replace entire file
- `skip` - Skip saving
- `cancel` - Cancel operation

## Testing

```bash
composer test
```
