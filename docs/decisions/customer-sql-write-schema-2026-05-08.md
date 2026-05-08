# Customer SQL Write Schema Decision

Date: 2026-05-08
Database: `BILNEX_CRMDB`

This document records the read-only schema findings needed before enabling CRM writes to Bilnex SQL Server. No persistent database changes were made while preparing this document.

## Target Tables

### `dbo.Customer`

Purpose: main customer/account card.

Key fields:

| Field | Type | Required by DB | Write rule |
| --- | --- | --- | --- |
| `Id` | `int identity` | yes | Do not provide. Read back with `SCOPE_IDENTITY()`. |
| `CustomerTypeId` | `int` | yes | Required CRM mapping. |
| `MainCustomerId` | `int` | yes | Use `1` for new independent CRM records unless a parent customer is selected. |
| `CustomerTaxType` | `bit` | yes | `0` for individual/unknown, `1` for company/tax entity. |
| `Name1` | `nvarchar(100)` | no | CRM must require this as company/customer name. |
| `Name2` | `nvarchar(100)` | no | Primary contact name for the first write phase. |
| `TaxOffice` | `nvarchar(50)` | no | Optional. |
| `TaxNumber` | `nvarchar(250)` | no | Optional. |
| `Description` | `nvarchar(255)` | no | Optional, truncate at 255. |
| `StaffId` | `int` | no | Use `0` unless mapped to a Bilnex staff/user. |
| `CreatedDate` | `datetime` | no | Use `GETDATE()`. |
| `CreatedUserId` | `int` | no | Use `-1` as system/CRM default unless mapped to `dbo.[User].Id`. |
| `isActive` | `bit` | no | Use `1`. |
| `isDeleted` | `bit` | no | Use `0`. |
| `DeletedDate` | `datetime` | no | Use `NULL`. |
| `GroupId` | `int` | no | Use `NULL` unless a group is selected. |
| `RegionId` | `int` | no | Use `NULL` unless a region is selected. |
| `CategoryId` | `int` | no | Use `NULL` unless a category is selected. |
| `Code` | `nvarchar(30)` | no | Generate `120-CRM-{nextSuffix}`. |
| `isDemoRecord` | `bit` | no | Use `0` unless demo customer flow is selected. |
| `RepresentativeId` | `int` | no | Use `NULL` unless mapped to a representative. |

Observed indexes: primary key on `Id`; non-unique indexes on `CustomerTypeId`, `MainCustomerId`, `Name1`, `RegionId + CategoryId`, and `isActive + isDeleted`.

Important: there is no unique index on `Code`; the CRM must prevent duplicate code generation in application logic.

### `dbo.Address`

Purpose: address, phone, and e-mail information for a customer.

Key fields:

| Field | Type | Required by DB | Write rule |
| --- | --- | --- | --- |
| `Id` | `int identity` | yes | Do not provide. |
| `Guid` | `uniqueidentifier` | yes | Generate a new GUID. |
| `Address1` | `nvarchar(100)` | no | First address line, truncate at 100. |
| `Address2` | `nvarchar(100)` | no | Optional second line. |
| `Country` | `nvarchar(25)` | no | Use `TR` for Turkey records. |
| `City` | `nvarchar(25)` | no | Store `dbo.City.Code`, not display name. |
| `Town` | `nvarchar(25)` | no | Store `dbo.District.Code`, not display name. |
| `PostCode` | `nvarchar(10)` | no | Optional. |
| `Phone` | `nvarchar(250)` | no | Primary phone. |
| `EMail` | `nvarchar(250)` | no | Primary e-mail. |
| `Web` | `nvarchar(50)` | no | Optional. |
| `CustomerId` | `int` | yes | Use the inserted `dbo.Customer.Id`. |
| `BranchName` | `nvarchar(100)` | no | Use `Merkez` or `Isyeri Adresi`. |
| `CreatedDate` | `datetime` | no | Use `GETDATE()`. |
| `CreatedUserId` | `int` | no | Same rule as `Customer.CreatedUserId`. |
| `isActive` | `bit` | no | Use `1`. |
| `isDeleted` | `bit` | no | Use `0`. |
| `DeletedDate` | `datetime` | no | Use `NULL`. |
| `isEInvoice` | `bit` | no | Use `0` unless e-invoice flow is selected. |

Observed indexes: primary key on `Id`; non-unique index on `CustomerId`; non-unique index on `isActive + isDeleted`.

### `dbo.CustomerRep`

Purpose: additional customer representatives/contacts.

Key fields: `Id` identity, `CustomerId` required, `Guid` required, plus optional `Name`, `Phone`, `EMail`, `Description`, confirmation flags, dates, and active/deleted flags.

Decision for first SQL write phase: do not write `CustomerRep` unless multiple contacts are needed. Store the primary contact in `Customer.Name2` and primary phone/e-mail in `Address`.

## Type Mapping

Observed `dbo.CustomerType` values relevant to CRM:

| CRM concept | `CustomerTypeId` | Bilnex name | Active not-deleted count observed |
| --- | ---: | --- | ---: |
| Business partner | `7` | `Is Ortaklari` | `0` |
| Authorized seller | `12` | `Yetkili Satici` | `668` |
| Target dealer | `14` | `Hedef Bayi` | `0` |
| Customer | `16` | `Musteri` | `8162` |
| Target customer | `17` | `Hedef Musteri` | `13` |
| Demo customer | `18` | `Demo Musteri` | `596` |

Recommended CRM defaults:

- New dealer candidate: `CustomerTypeId = 14`
- Confirmed dealer / reseller: choose one explicitly before implementation: either `7` or the existing operational type `12`
- New end-customer candidate: `CustomerTypeId = 17`
- Confirmed end customer: `CustomerTypeId = 16`

## Code Generation

Existing CRM code pattern: `120-CRM-{number}`.

Current maximum observed suffix: `13918`.
Next candidate at inspection time: `120-CRM-13919`.

Because `Code` is not unique at the database level, write logic must:

1. Open a SQL transaction.
2. Read the current max suffix with update/serializable protection.
3. Generate the next code.
4. Check no active row already has that code.
5. Insert `Customer`.
6. Insert `Address`.
7. Commit.

## City and District

`Address.City` and `Address.Town` store codes, not display names.

Reference tables:

- `dbo.City`: `Code`, `Name`, `CountryCode`
- `dbo.District`: `Code`, `Name`, `CityCode`
- `dbo.Country`: country reference

Examples:

- City `07` = Antalya
- District `2039` = Muratpasa
- Country for Turkey records is `TR`

CRM form should resolve typed city/district names to these codes before writing. If a district cannot be matched, the write should be blocked or explicitly saved with a reviewed fallback rule.

## Relationship Notes

No SQL foreign key constraints were found between the inspected customer tables. The database relies on application-level consistency for these relationships:

- `Address.CustomerId` -> `Customer.Id`
- `CustomerRep.CustomerId` -> `Customer.Id`
- `Customer.CustomerTypeId` -> `CustomerType.CustomerTypeId`
- optional group/region/category references

CRM write code must enforce these relationships itself.

## Minimal Write Shape

For a new CRM customer/dealer write:

1. Insert `dbo.Customer` with:
   - `CustomerTypeId`
   - `MainCustomerId = 1`
   - `CustomerTaxType`
   - `Name1`
   - `Name2`
   - optional tax fields
   - `Description`
   - `StaffId = 0`
   - `CreatedDate = GETDATE()`
   - `CreatedUserId = -1`
   - `isActive = 1`
   - `isDeleted = 0`
   - `GroupId/RegionId/CategoryId = NULL`
   - generated `Code`
   - `isDemoRecord = 0`
   - `RepresentativeId = NULL`

2. Read inserted `Customer.Id`.

3. Insert `dbo.Address` with:
   - new `Guid`
   - address fields
   - `Country = TR`
   - `City = dbo.City.Code`
   - `Town = dbo.District.Code`
   - `Phone`
   - `EMail`
   - `CustomerId`
   - `BranchName = Merkez`
   - `CreatedDate = GETDATE()`
   - `CreatedUserId = -1`
   - `isActive = 1`
   - `isDeleted = 0`
   - `isEInvoice = 0`

4. Optionally insert `CustomerRep` only when a separate representative/contact model is required.

## Open Decision Before Implementation

The only product decision still needed before coding writes is dealer type:

- Should a new CRM dealer be created as `CustomerTypeId = 14` (`Hedef Bayi`) first, and later promoted?
- Or should it be created directly as `CustomerTypeId = 12` (`Yetkili Satici`) / `7` (`Is Ortaklari`)?

Technical schema is otherwise sufficient for a guarded `Customer + Address` write implementation.
