$files = Get-ChildItem -Path "admin" -Recurse -File | Where-Object { $_.Extension -in ".php", ".html" }
$updated = 0

foreach ($f in $files) {
    $content = Get-Content -Raw -Path $f.FullName
    $newContent = $content

    # Remove existing approval settings entry from wherever it exists
    $newContent = [regex]::Replace(
        $newContent,
        '(?m)^\s*<li><a href="account_approval_settings\.php"[^\n]*\r?\n',
        ''
    )

    # Insert dedicated Approval group before Account group (once per file)
    $newContent = [regex]::Replace(
        $newContent,
        '(?m)(\s*<div class="menu-group">\r?\n\s*<div class="menu-group-title">Account</div>)',
        @'
            <div class="menu-group">
                <div class="menu-group-title">Approval</div>
                <li><a href="account_approval_settings.php"><img src="../pix/set.png" alt="Approval Settings"> Approval Settings</a></li>
            </div>

$1
'@,
        1
    )

    # Make active in approval page itself
    if ($f.Name -ieq "account_approval_settings.php") {
        $newContent = $newContent -replace '<a href="account_approval_settings\.php">', '<a href="account_approval_settings.php" class="active">'
    }

    # Remove approval option tile from settings page content area
    if ($f.Name -ieq "settings.html") {
        $newContent = [regex]::Replace(
            $newContent,
            '(?ms)\s*<!--\s*Account Management Option\s*-->\s*<div class="option" onclick="window\.location\.href=''account_approval_settings\.php''">\s*<img[^>]*>\s*<label>Account Management</label>\s*</div>\s*',
            ''
        )
    }

    if ($newContent -ne $content) {
        Set-Content -Path $f.FullName -Value $newContent -Encoding UTF8
        $updated++
    }
}

Write-Output "Updated files: $updated"
