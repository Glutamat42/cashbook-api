# Setup
start db (docker compose up -d)

initial setup stuff
php artisan migrate



create user: php artisan user:create <username> <password>





open php builtin webserver inside WSL to lan: netsh interface portproxy add v4tov4 listenport=8000 listenaddress=0.0.0.0 connectport=8000 connectaddress=172.26.202.84 


upload_max_filesize and post_max_size  min 10MB

requires imagemagick and ghostscript

iconv, dom: seems at least one of these is required for league/csv

Edit the Imagick Policy File:

Locate and edit the Imagick policy file, usually named policy.xml. This file can typically be found at /etc/ImageMagick-6/policy.xml or /etc/ImageMagick-7/policy.xml, depending on the version and system.

Look for a policy with PDF in the pattern attribute and Rights attribute set to None. It will look something like this:

xml

<policy domain="coder" rights="none" pattern="PDF" />

Change rights="none" to rights="read|write" to enable PDF processing:

xml

<policy domain="coder" rights="read|write" pattern="PDF" />


---

create user: docker exec api php /var/www/html/artisan user:create <username> <password> <token>

data export: php artisan export:data --exportDocuments --convertToJpeg --exportFormat=tar.gz
docker compose exec -it api su www-data -s /bin/sh -c "php artisan export:data --exportDocuments --convertToJpeg --exportFormat=tar.gz"

## csv import
The script `excel_import.py` is a quick n dirty solution to import csv exports from Sparkasse via API.

> [!IMPORTANT]
> This script is not properly tested and should be used with caution.

The excel file (not a csv, but an xlsx) has to have the following (named) columns (mappings can be changed in the scripts config object):
- category
- Betrag
- Buchungstag
- Verwendungszweck
- Beguenstigter/Zahlungspflichtiger

- is_income (yes/no)
- no_invoice (yes/no)

In the script the following configs have to be set:
- 'api_endpoint': '<api url of backend>'
- 'username': '<your username>',
- 'password': '<your password>',
- 'excel_file': '<path to excel file>',

### Notes
Things i noticed while using:
- Date column has to be properly formatted. If year format is short (eg 10.10.24) it might fail. fix it in excel to be
  in long year format (10.10.2024)
