<#
.SYNOPSIS
    Mengekspor 9 tabel VMS-old dari SQL Server ke CSV ber-quote RFC 4180 streaming.
.DESCRIPTION
    Menggunakan System.Data.SqlClient bawaan Windows PowerShell 5.1 tanpa driver tambahan.
    Mendukung ekspor delta melalui parameter -Since dan menghasilkan manifest.json.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$Server = "localhost,1433",

    [Parameter(Mandatory = $false)]
    [string]$Database = "ASL-ASL-HRMS",

    [Parameter(Mandatory = $true)]
    [string]$OutputDir,

    [Parameter(Mandatory = $false)]
    [datetime]$Since = [datetime]::MinValue,

    [Parameter(Mandatory = $false)]
    [System.Management.Automation.PSCredential]$Credential,

    [Parameter(Mandatory = $false)]
    [string]$Username,

    [Parameter(Mandatory = $false)]
    [string]$Password
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path -Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

if (-not $Credential) {
    if ($Username -and $Password) {
        $secPass = ConvertTo-SecureString $Password -AsPlainText -Force
        $Credential = New-Object System.Management.Automation.PSCredential ($Username, $secPass)
    } else {
        $Credential = Get-Credential -Message "Masukkan kredensial login SQL Server untuk $Server"
    }
}

$builder = New-Object System.Data.SqlClient.SqlConnectionStringBuilder
$builder["Data Source"] = $Server
$builder["Initial Catalog"] = $Database
$builder["User ID"] = $Credential.UserName
$builder["Password"] = $Credential.GetNetworkCredential().Password
$builder["TrustServerCertificate"] = $true
$builder["Application Name"] = "VMS-Old-Exporter"

$conn = New-Object System.Data.SqlClient.SqlConnection($builder.ConnectionString)
$conn.Open()

function Escape-CsvField([string]$value) {
    if ($null -eq $value) { return '""' }
    return '"' + $value.Replace('"', '""') + '"'
}

$tables = @(
    @{ Name = "Nationality"; Query = "SELECT NationalityCode, Description, Note FROM Nationality ORDER BY NationalityCode"; Incremental = $false },
    @{ Name = "ESSVesselMaster"; Query = "SELECT IMONo, Description, CallSign, Sign, NationalityFlag, Port, Type, Captain, LocalCompanyAgent, LocalCompanyAgentName, LocalCompanyAgentPhoneNo, LocalCompanyAgentLocation, ForeignCompanyAgent, ForeignCompanyAgentName, ForeignCompanyAgentPhoneNo, ForeignCompanyAgentLocation, Note, isVessel, LastModifiedBy, LastModifiedDate FROM ESSVesselMaster"; Incremental = $true },
    @{ Name = "ESSStaffToVisit"; Query = "SELECT StaffID, StaffFullName, StaffGender, StaffBirthDate, StaffCompany, StaffAddress, StaffOccupation, VisitorStatus, Note, LastModifiedBy, LastModifiedDate FROM ESSStaffToVisit"; Incremental = $true },
    @{ Name = "ESSVisitorCardMaster"; Query = "SELECT CardNo, AbsenteeismNo, ActiveStatus, Note, LastModifiedBy, LastModifiedDate FROM ESSVisitorCardMaster"; Incremental = $true },
    @{ Name = "ESSVisitorMaster"; Query = "SELECT VisitorID, VisitorIDType, VisitorIDNumber, VisitorFullName, VisitorGender, VisitorBirthDate, VisitorCompany, VisitorAddress, VisitorOccupation, VisitorType, VisitorStatus, Note, LastModifiedBy, LastModifiedDate, IMONo, ExpiryDate, IssueDate, SeamanBookExpiryDate, PhoneNo, PlaceofBirth, Certificate, CertificateNo, SeamanBookNo FROM ESSVisitorMaster"; Incremental = $true },
    @{ Name = "ESSVisitorRegistration"; Query = "SELECT VisitorID, CardNo, TransactionDate, SequenceNo, VehicleNo, EmployeeToVisit, VisitLocation, VisitPurpose, CheckInTime, CheckOutTime, Note, LastModifiedBy, LastModifiedDate, VisitorRegistrationNo, VisitorCompany, StaffID FROM ESSVisitorRegistration"; Incremental = $true },
    @{ Name = "ESSVesselTransaction"; Query = "SELECT SequenceNo, IMONo, AssignmentSignIn, AssignmentSignOut, PortSignIn, PortSignOut, Captain, LocalCompanyAgent, LocalCompanyAgentName, LocalCompanyAgentPhoneNo, LocalCompanyAgentLocation, ForeignCompanyAgent, ForeignCompanyAgentName, ForeignCompanyAgentPhoneNo, ForeignCompanyAgentLocation, Note, LastModifiedBy, LastModifiedDate FROM ESSVesselTransaction"; Incremental = $true },
    @{ Name = "ESSCrewTransaction"; Query = "SELECT SequenceNo, IMONo, VisitorID, Note, LastModifiedBy, LastModifiedDate, TransactionID FROM ESSCrewTransaction"; Incremental = $true },
    @{ Name = "EmployeeMaster"; Query = "SELECT p.EmployeeNo, p.StatusAdmin, m.FullName FROM EmployeeSelfServicePassword p LEFT JOIN EmployeeMaster m ON p.EmployeeNo = m.EmployeeNo"; Incremental = $false }
)

$manifest = @{
    source = "vms-old"
    exported_at = (Get-Date).ToString("o")
    since = if ($Since -gt [datetime]::MinValue) { $Since.ToString("o") } else { $null }
    tables = @{}
}

try {
    foreach ($tbl in $tables) {
        $tableName = $tbl.Name
        $filePath = Join-Path $OutputDir "$tableName.csv"
        Write-Host "Mengekspor $tableName..." -ForegroundColor Cyan

        $sql = $tbl.Query
        if ($tbl.Incremental -and $Since -gt [datetime]::MinValue) {
            $clause = if ($sql -match "WHERE") { " AND LastModifiedDate >= @Since" } else { " WHERE LastModifiedDate >= @Since" }
            $sql += $clause
        }

        $cmd = $conn.CreateCommand()
        $cmd.CommandText = $sql
        $cmd.CommandTimeout = 3600
        if ($tbl.Incremental -and $Since -gt [datetime]::MinValue) {
            $param = $cmd.Parameters.Add("@Since", [System.Data.SqlDbType]::DateTime)
            $param.Value = $Since
        }

        $reader = $cmd.ExecuteReader()
        $writer = New-Object System.IO.StreamWriter($filePath, $false, [System.Text.Encoding]::UTF8)

        $colCount = $reader.FieldCount
        $headers = 0..($colCount - 1) | ForEach-Object { Escape-CsvField $reader.GetName($_) }
        $writer.WriteLine(($headers -join ","))

        $rowCount = 0
        $maxLmd = [datetime]::MinValue

        while ($reader.Read()) {
            $rowValues = 0..($colCount - 1) | ForEach-Object {
                if ($reader.IsDBNull($_)) {
                    '""'
                } else {
                    $val = $reader.GetValue($_)
                    if ($val -is [datetime]) {
                        Escape-CsvField ($val.ToString("yyyy-MM-dd HH:mm:ss.fff"))
                    } else {
                        Escape-CsvField ($val.ToString())
                    }
                }
            }
            $writer.WriteLine(($rowValues -join ","))
            $rowCount++

            if ($tbl.Incremental) {
                $lmdIndex = $reader.GetOrdinal("LastModifiedDate")
                if (-not $reader.IsDBNull($lmdIndex)) {
                    $currLmd = $reader.GetDateTime($lmdIndex)
                    if ($currLmd -gt $maxLmd) { $maxLmd = $currLmd }
                }
            }
        }

        $reader.Close()
        $writer.Close()

        $manifest.tables[$tableName] = @{
            file = "$tableName.csv"
            row_count = $rowCount
            max_last_modified = if ($maxLmd -gt [datetime]::MinValue) { $maxLmd.ToString("o") } else { $null }
        }
        Write-Host "Selesai ${tableName}: $rowCount baris." -ForegroundColor Green
    }

    $manifestPath = Join-Path $OutputDir "manifest.json"
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -Path $manifestPath -Encoding UTF8
    Write-Host "Ekspor selesai. Manifest ditulis ke $manifestPath" -ForegroundColor Green
}
finally {
    $conn.Close()
}
