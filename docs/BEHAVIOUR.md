# Behaviour

How the import methods read a file, how the interfaces an importer implements change what it receives,
and how column names are built.

## Building an importer

An importer is a class that uses the `Importable` trait and implements one or more interfaces:

```php
use GNfsys\SheetImport\Importable;
use GNfsys\SheetImport\OnEachRow;
use GNfsys\SheetImport\WithHeadingRow;
use GNfsys\SheetImport\WithStartRow;

class UsersImport implements OnEachRow, WithHeadingRow, WithStartRow
{
    use Importable;

    public function headingRow(): int
    {
        return 4;
    }

    public function startRow(): int
    {
        return 5;
    }

    public function onRow(array $row, int $offset, int $index): void
    {
        // $row['ragione_sociale'], ...
    }
}

new UsersImport()->importFromFile('/path/to/file.xlsx');
```

- **`use Importable`** (the trait) gives the class the import methods `importFromFile()`, `importFromString()` and `import()`,
  plus `convertToCsv()`, `loadCsv()` and `cleanupTempFiles()`.
- **`implements ...`** (the interfaces) switch behaviours on. The import methods check `$this instanceof ...` for each one,
  so implementing an interface is all it takes.

| Interface           | Methods to write                                           | Effect                                                            |
|---------------------|------------------------------------------------------------|-------------------------------------------------------------------|
| `OnEachRow`         | `onRow(array $row, int $offset, int $index): void`         | Called once for every row                                         |
| `ToIterator`        | `handleIterator(TabularDataReader $records): void`         | Receives all the rows at once, `onRow()` is then never called     |
| `WithHeadingRow`    | `headingRow(): int`, optional, defaults to `1`             | One row provides the column names, rows become `name => value`    |
| `WithStartRow`      | `startRow(): int`                                          | Rows before this one are skipped                                  |
| `WithTempDirectory` | `tempDirectory(): string`                                  | Where temporary files go, see [USAGE.md](../USAGE.md)                |

An importer implementing neither `OnEachRow` nor `ToIterator` reads the file and does nothing with it.

## What the import methods do

1. **Validates the settings.** `startRow()` and `headingRow()` must be at least `1`, otherwise a
   `SheetImportException` is thrown before anything is read.
2. **Gets a CSV.**
   - `importFromFile($path)` reads a file. It throws a `SheetImportException` when the path doesn't exist,
     isn't a file or isn't readable. The file type is detected:
     - plain text and CSV are read as they are
     - HTML, e.g. an `.xls` exported by a website, is read from its first table. Every row is kept as it is,
       `<thead>` and `<tfoot>` included, in the order they are written. `headingRow()` picks the heading row
       like for any other file. A cell spanning several columns repeats its value in each of them.
       A file without a table throws an `HtmlConversionException`
     - anything else (xlsx, xls, ods, ...) is converted to CSV by LibreOffice
   - `importFromString($content)` reads `$content` as CSV, never as a path. Empty content throws a `SheetImportException`.
   - `import($file)` guesses: the path of an existing file is read like `importFromFile()`, anything else like
     `importFromString()`. A mistyped path is therefore imported as a single row containing the path, and CSV content
     that happens to be the path of an existing file reads that file.
3. **Guesses the delimiter.** Between `,` and `;`, the one that splits the first 16 rows into more cells wins,
   `,` on a tie.
4. **Reads the column names**, only with `WithHeadingRow`, see [Column names](#column-names).
5. **Selects the rows**, see [Rows and row numbers](#rows-and-row-numbers).
6. **Hands the rows over**, to `handleIterator()` if the importer implements `ToIterator`,
   otherwise to `onRow()` if it implements `OnEachRow`.
7. **Removes its own temporary files**, also when a handler throws,
   but not if the script stops inside a handler with `exit`, `die` or `dd()`, as PHP skips `finally` blocks then.
   Files from your own `convertToCsv()` calls, made before or during the import, are never touched:
   remove them with `cleanupTempFiles()`.

## Rows and row numbers

`onRow(array $row, int $offset, int $index)` receives:

- **`$row`**, the cells of the row, as a list or, with `WithHeadingRow`, as `name => value`
- **`$offset`**, the row number in the file, one based. Skipped rows still count,
  so it always matches the row number shown in the spreadsheet
- **`$index`**, the position among the rows handed over, zero based. It's `0` for the first call
  even when the import starts from a later row

Completely empty rows are never handed over, but they still count for `$offset`.
A cell containing line breaks is still a single row.

`ToIterator` receives the same rows. Its keys are the zero based row offsets in the file, so the row number is
`$key + 1`. They are not positions: `$records->nth($n)` counts the rows handed over and ignores the keys.

### `WithHeadingRow`

- The heading row is never handed over.
- The other rows become `name => value`. A missing cell is `null`, cells beyond the last column name are dropped.
- Rows above the heading row are handed over too, with the same names. Use `WithStartRow` to skip them.

### `WithStartRow`

- Rows before `startRow()` are skipped.
- It's counted from the top of the file, wherever the heading row is.
  A start row on or above the heading row still never hands over the heading row.

### Every combination on one file

```
1  Name,City
2  Ann,Rome
3
4  Bob,Milan,EXTRA
5  Cid
```

Each call is shown as `$offset/$index → $row`.

| Importer implements                                   | Calls to `onRow()`                                                                                  |
|-------------------------------------------------------|-----------------------------------------------------------------------------------------------------|
| `OnEachRow`                                           | `1/0 [Name, City]` · `2/1 [Ann, Rome]` · `4/2 [Bob, Milan, EXTRA]` · `5/3 [Cid]`                     |
| + `WithHeadingRow`                                    | `2/0 {name: Ann, city: Rome}` · `4/1 {name: Bob, city: Milan}` · `5/2 {name: Cid, city: null}`      |
| + `WithHeadingRow`, `headingRow()` = 2                | `1/0 {ann: Name, rome: City}` · `4/1 {ann: Bob, rome: Milan}` · `5/2 {ann: Cid, rome: null}`        |
| + `WithStartRow`, `startRow()` = 4                    | `4/0 [Bob, Milan, EXTRA]` · `5/1 [Cid]`                                                             |
| + both, `headingRow()` = 1, `startRow()` = 4          | `4/0 {name: Bob, city: Milan}` · `5/1 {name: Cid, city: null}`                                      |
| + both, `headingRow()` = 2, `startRow()` = 1          | Same as `headingRow()` = 2 alone                                                                    |
| + `ToIterator`                                        | None, `handleIterator()` receives the rows                                                          |

## Column names

Column names exist only with `WithHeadingRow`. By default the heading row is formatted, then duplicates are renamed.

### Formatting

Each name is turned into a slug:

- accents and other non ASCII letters are transliterated
- letters are lowercased
- `@` becomes `at`
- spaces, `-` and `_` become a single `_`, removed at both ends
- anything else is removed

| Heading             | Column name       |
|---------------------|-------------------|
| `Ragione Sociale`   | `ragione_sociale` |
| `  Codice Fiscale ` | `codice_fiscale`  |
| `E-mail`            | `e_mail`          |
| `Nr. Ordine`        | `nr_ordine`       |
| `Città`             | `citta`           |
| `Größe`             | `grosse`          |
| `Prezzo (€)`        | `prezzo_eur`      |
| `Prezzo %`          | `prezzo`          |
| `Data/Ora`          | `dataora`         |
| `info@azienda`      | `info_at_azienda` |
| `%` or empty        | empty string      |

### Duplicates

Names that are equal after formatting are renamed, left to right, by appending `_1`, `_2`, ...
The first one keeps its name.

A generated name never takes the name of a real column: a suffix already used by a column is skipped,
so `$row['a_1']` is always the column whose heading is `a_1`.

| Heading row              | Column names              |
|--------------------------|---------------------------|
| `a, a, a`                | `a, a_1, a_2`             |
| `Città, Citta`           | `citta, citta_1`          |
| `%, (empty)`             | `(empty), _1`             |
| `a, a_1, a`              | `a, a_1, a_2`             |
| `a, a, a_1`              | `a, a_2, a_1`             |
| `a, a, a_1, a_2, a`      | `a, a_3, a_1, a_2, a_4`   |

### Custom formatting

The second argument of the import methods replaces both steps. It receives the heading row as it is in the file and
must return the column names:

```php
use GNfsys\SheetImport\Common;

$importer->importFromFile($path, fn (array $header): array => Common::handleDuplicates(array_map('strtoupper', $header)));
```

- Duplicates are not renamed for you, call `Common::handleDuplicates()` as above if they can occur.
  Duplicate names make the import fail with a `League\Csv\SyntaxError`.
- Returning anything but an array throws a `SheetImportException`.
- Without `WithHeadingRow` the callable is ignored.
