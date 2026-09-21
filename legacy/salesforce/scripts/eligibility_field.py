"""Prepare an org-specific eligibility field deployment or removal manifest.

Usage: python3 scripts/eligibility_field.py add Course__c [--formula "Status__c = 'Approved'"]
       python3 scripts/eligibility_field.py remove Course__c
Deploy the generated local-setup metadata only after reviewing the target org.
"""
import argparse
import re
from pathlib import Path
from xml.sax.saxutils import escape

parser = argparse.ArgumentParser()
parser.add_argument("action", choices=("add", "remove"))
parser.add_argument("object_api")
parser.add_argument("--formula")
args = parser.parse_args()
if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]*", args.object_api):
    parser.error("Invalid Salesforce object API name")

root = Path(__file__).resolve().parents[1] / "local-setup"
full_name = args.object_api + ".WFC27_Eligible__c"
namespace = 'xmlns="http://soap.sforce.com/2006/04/metadata"'
if args.action == "add":
    dest = root / "force-app/main/default/objects" / args.object_api / "fields" / "WFC27_Eligible__c.field-meta.xml"
    dest.parent.mkdir(parents=True, exist_ok=True)
    details = (f"<formula>{escape(args.formula)}</formula><formulaTreatBlanksAs>BlankAsZero</formulaTreatBlanksAs>"
               if args.formula else "<defaultValue>false</defaultValue>")
    dest.write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<CustomField {namespace}>'
                    f'<fullName>WFC27_Eligible__c</fullName><label>WFC27 Eligible</label><type>Checkbox</type>{details}'
                    '</CustomField>\n')
    print(f"Review {dest}, then run: sf project deploy start --source-dir local-setup/force-app --target-org YOUR_ORG")
else:
    dest = root / "destructive"
    dest.mkdir(parents=True, exist_ok=True)
    (dest / "package.xml").write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<Package {namespace}><version>66.0</version></Package>\n')
    (dest / "destructiveChanges.xml").write_text(f'<?xml version="1.0" encoding="UTF-8"?>\n<Package {namespace}>'
                                                  f'<types><members>{full_name}</members><name>CustomField</name></types>'
                                                  '<version>66.0</version></Package>\n')
    print(f"Review {dest}. Deleting this field also deletes its data; deactivate the binding first.")
    print("Then run: sf project deploy start --metadata-dir local-setup/destructive --target-org YOUR_ORG")
