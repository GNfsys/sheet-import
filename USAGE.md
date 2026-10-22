# Usage

## Temporary files

Every conversion runs in its own directory (converted csv and LibreOffice profile).
`importFromFile()` and `import()` delete the one they create when they end.
If you call `convertToCsv()` directly, call `cleanupTempFiles()` once you're done with the returned file:
the import methods never delete it, not even when you call `convertToCsv()` while an import is running.

By default these directories are created in `sys_get_temp_dir()`. To change it for every importer, e.g. in a Laravel service provider:

```php
use GNfsys\SheetImport\SheetImport;

public function boot(): void
{
    SheetImport::setTempDirectory(storage_path('app/sheet-import'));
}
```

Missing parent directories are created. A single importer can override it by implementing `WithTempDirectory`:

```php
class UsersImport implements OnEachRow, WithTempDirectory
{
    use Importable;

    public function tempDirectory(): string
    {
        return '/var/tmp/users-import';
    }

    // ...
}
```
