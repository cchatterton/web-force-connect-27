"""Generate the package's small, regular Salesforce object metadata set."""

from pathlib import Path
from xml.sax.saxutils import escape
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1] / "force-app/main/default/objects"
NS = 'xmlns="http://soap.sforce.com/2006/04/metadata"'
NAMESPACE = "http://soap.sforce.com/2006/04/metadata"
ET.register_namespace("", NAMESPACE)


def ordered_xml(kind, body, keys):
    root = ET.fromstring(f"<{kind} {NS}>{body}</{kind}>")
    priority = {name: index for index, name in enumerate(keys)}
    root[:] = sorted(root, key=lambda element: priority.get(element.tag.split("}")[-1], 99))
    return '<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(root, encoding="unicode") + "\n"

OBJECTS = {
    "WFC27_Object_Binding__c": ("Object Binding", "Object Bindings", "Text"),
    "WFC27_Field_Binding__c": ("Field Binding", "Field Bindings", "AutoNumber"),
    "WFC27_Queue__c": ("Queue Item", "Queue Items", "AutoNumber"),
    "WFC27_Packet__c": ("Packet", "Packets", "AutoNumber"),
    "WFC27_Map__c": ("Identity Map", "Identity Maps", "AutoNumber"),
    "WFC27_Settings__c": ("Settings", "Settings", "Text"),
    "WFC27_Deletion_Ack__c": ("Deletion Acknowledgement", "Deletion Acknowledgements", "AutoNumber"),
}

FIELDS = {
    "WFC27_Object_Binding__c": {
        "Object_API_Name__c": ("Text", "Salesforce Object API Name", 80),
        "WP_Post_Type__c": ("Text", "WordPress Post Type", 80),
        "Eligible_Status__c": ("Text", "Status When Eligible", 20),
        "Active__c": ("Checkbox", "Active", False),
        "Draft_Retention_Days__c": ("Number", "Draft Retention Days", (5, 0)),
        "Bin_Retention_Days__c": ("Number", "Bin Retention Days", (5, 0)),
        "Scan_Cursor__c": ("Text", "Live Scan Cursor", 18),
    },
    "WFC27_Field_Binding__c": {
        "Object_Binding__c": ("Lookup", "Object Binding", "WFC27_Object_Binding__c"),
        "Source_Field__c": ("Text", "Source Field API Name", 80),
        "Target_Type__c": ("Text", "Target Type", 20),
        "WP_Key__c": ("Text", "WordPress Key", 255),
        "Active__c": ("Checkbox", "Active", False),
    },
    "WFC27_Queue__c": {
        "Source_ID__c": ("Text", "Salesforce Source ID", 18),
        "Source_Object__c": ("Text", "Source Object", 80),
        "Kind__c": ("Text", "Kind", 20),
        "Status__c": ("Text", "Status", 20),
        "Packet__c": ("Lookup", "Packet", "WFC27_Packet__c"),
        "Source_Modified_At__c": ("DateTime", "Source Modified At", None),
    },
    "WFC27_Packet__c": {
        "Payload__c": ("LongTextArea", "JSON Payload", 131072),
        "Status__c": ("Text", "Status", 20),
        "Sent_At__c": ("DateTime", "Sent At", None),
        "Completed_At__c": ("DateTime", "Completed At", None),
        "Post_Count__c": ("Number", "Post Count", (7, 0)),
        "Meta_Count__c": ("Number", "Postmeta Count", (7, 0)),
        "Error__c": ("LongTextArea", "Error", 32768),
    },
    "WFC27_Map__c": {
        "Source_ID__c": ("TextUnique", "Salesforce Source ID", 18),
        "Source_Object__c": ("Text", "Source Object", 80),
        "WP_Post_ID__c": ("Number", "WordPress Post ID", (18, 0)),
        "WP_Status__c": ("Text", "WordPress Status", 20),
        "Status_Entered_At__c": ("DateTime", "Status Entered At", None),
        "Last_Source_Modified__c": ("DateTime", "Last Source Modified", None),
        "Last_Eligible__c": ("Checkbox", "Last Eligible", False),
        "Last_Sent_At__c": ("DateTime", "Last Sent At", None),
        "Meta_IDs_JSON__c": ("LongTextArea", "Meta IDs JSON", 32768),
    },
    "WFC27_Settings__c": {
        "Batch_Size__c": ("Number", "Items Per Train", (4, 0)),
        "Last_Tick_At__c": ("DateTime", "Last Train At", None),
        "Last_Scan_At__c": ("DateTime", "Last Scan At", None),
        "Last_Base_Sync_At__c": ("DateTime", "Last Base Sync At", None),
    },
    "WFC27_Deletion_Ack__c": {
        "Source_ID__c": ("Text", "Salesforce Source ID", 18),
        "WP_Post_ID__c": ("Number", "WordPress Post ID", (18, 0)),
        "Status__c": ("Text", "Status", 20),
    },
}


def field_xml(name, kind, label, value):
    body = f"<fullName>{name}</fullName><label>{escape(label)}</label>"
    if kind in ("Text", "TextUnique"):
        body += f"<type>Text</type><length>{value}</length>"
        if kind == "TextUnique":
            body += "<unique>true</unique><externalId>true</externalId>"
    elif kind == "Checkbox":
        body += f"<type>Checkbox</type><defaultValue>{str(value).lower()}</defaultValue>"
    elif kind == "Number":
        body += f"<type>Number</type><precision>{value[0]}</precision><scale>{value[1]}</scale>"
    elif kind == "DateTime":
        body += "<type>DateTime</type>"
    elif kind == "LongTextArea":
        body += f"<type>LongTextArea</type><length>{value}</length><visibleLines>6</visibleLines>"
    elif kind == "Lookup":
        body += f"<type>Lookup</type><referenceTo>{value}</referenceTo><relationshipLabel>{escape(label)}</relationshipLabel><relationshipName>{name.removesuffix('__c')}</relationshipName>"
    else:
        raise ValueError(kind)
    return ordered_xml("CustomField", body, ["fullName", "defaultValue", "externalId", "label", "length", "precision", "referenceTo", "relationshipLabel", "relationshipName", "scale", "type", "unique", "visibleLines"])


for api_name, (label, plural, name_type) in OBJECTS.items():
    folder = ROOT / api_name
    (folder / "fields").mkdir(parents=True, exist_ok=True)
    if name_type == "AutoNumber":
        short = api_name.replace("WFC27_", "").replace("__c", "").upper()
        name_field = f"<nameField><displayFormat>{short}-{{000000}}</displayFormat><label>{label} Number</label><type>AutoNumber</type></nameField>"
    else:
        name_field = f"<nameField><label>{label} Name</label><type>Text</type></nameField>"
    body = f"<label>WFC27 {label}</label><pluralLabel>WFC27 {plural}</pluralLabel>{name_field}<deploymentStatus>Deployed</deploymentStatus><sharingModel>ReadWrite</sharingModel>"
    (folder / f"{api_name}.object-meta.xml").write_text(ordered_xml("CustomObject", body, ["deploymentStatus", "label", "nameField", "pluralLabel", "sharingModel"]))
    for field_name, (kind, field_label, value) in FIELDS[api_name].items():
        (folder / "fields" / f"{field_name}.field-meta.xml").write_text(field_xml(field_name, kind, field_label, value))
