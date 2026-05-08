param(
    [Parameter(Mandatory = $true)]
    [string]$QueryBase64,

    [string]$ParamsBase64 = ""
)

$ErrorActionPreference = "Stop"

function Decode-Utf8([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) {
        return ""
    }
    return [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($Value))
}

$query = Decode-Utf8 $QueryBase64
$paramsJson = Decode-Utf8 $ParamsBase64
$params = @{}
if ($paramsJson) {
    $parsed = $paramsJson | ConvertFrom-Json
    if ($parsed -and $parsed -isnot [array]) {
        foreach ($property in $parsed.PSObject.Properties) {
            $params[$property.Name] = $property.Value
        }
    }
}

$server = $env:BILNEX_SQL_SERVER
if (-not $server) { $server = "localhost\BILNEXSQLCRM" }
$database = $env:BILNEX_SQL_DATABASE
if (-not $database) { $database = "BILNEX_CRMDB" }
$username = $env:BILNEX_SQL_USERNAME
$password = $env:BILNEX_SQL_PASSWORD

if (-not $username -or -not $password) {
    throw "BILNEX_SQL_USERNAME ve BILNEX_SQL_PASSWORD ortam degiskenleri gereklidir."
}

$connectionString = "Server=$server;Database=$database;User ID=$username;Password=$password;Encrypt=False;TrustServerCertificate=True;Connection Timeout=10;"
$connection = [System.Data.SqlClient.SqlConnection]::new($connectionString)
$command = $connection.CreateCommand()
$command.CommandText = $query

foreach ($key in $params.Keys) {
    $parameter = $command.Parameters.AddWithValue($key, $params[$key])
    [void]$parameter
}

$rows = New-Object System.Collections.Generic.List[object]
$connection.Open()
try {
    $reader = $command.ExecuteReader()
    try {
        while ($reader.Read()) {
            $row = [ordered]@{}
            for ($i = 0; $i -lt $reader.FieldCount; $i++) {
                $name = $reader.GetName($i)
                $value = $reader.GetValue($i)
                if ($value -is [DBNull]) {
                    $value = $null
                }
                $row[$name] = $value
            }
            $rows.Add([pscustomobject]$row)
        }
    } finally {
        $reader.Close()
    }
} finally {
    $connection.Close()
}

$rows | ConvertTo-Json -Depth 6 -Compress
