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

## LibreOffice options

Spreadsheets are converted by running `libreoffice` with the default `LibreOfficeOptions`.
To change them for every importer, e.g. the path of the binary in a Laravel service provider:

```php
use GNfsys\SheetImport\LibreOfficeOptions;
use GNfsys\SheetImport\SheetImport;

public function boot(): void
{
    SheetImport::setLibreOfficeOptions(new LibreOfficeOptions(executable: config('app.libreoffice_path')));
}
```

A single importer can override them by implementing `WithLibreOfficeOptions`:

```php
class UsersImport implements OnEachRow, WithLibreOfficeOptions
{
    use Importable;

    public function libreOfficeOptions(): LibreOfficeOptions
    {
        return new LibreOfficeOptions(executable: '/opt/libreoffice/program/soffice');
    }

    // ...
}
```

Both apply to the import methods and to `convertToCsv()` called without options.
Options passed to `convertToCsv()` are used for that call only.

### Choosing the sheet

Only the first sheet by position is read. Another one is chosen by its number, starting from 1:

```php
new LibreOfficeOptions(exportSheets: '2');
```

`LibreOfficeOptions::FIRST_SHEET` is the default. Every sheet at once (`-1`) isn't supported, as an import reads a single csv:
it throws a `SheetImportException` before LibreOffice runs. A sheet that doesn't exist makes the conversion fail.
