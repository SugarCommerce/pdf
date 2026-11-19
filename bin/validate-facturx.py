#!/usr/bin/env python3
"""Validate Factur-X XML against the official schema and the profile's own rules.

Two stages, which is what any conformance check does:

  1. XSD -- the UN/CEFACT CII D16B schema: structure, element order, types.
  2. Schematron -- the Factur-X rules for the profile the document declares,
     compiled to XSLT and run with Saxon, giving SVRL back. Minimum and
     Basic WL are not EN 16931 profiles, so each is marked against its own.

Fetch the artefacts with bin/validators xml, then run this with the Python
it installs:

    .validator/venv/bin/python bin/validate-facturx.py some.xml ...

Exits 0 when every file passes both, 1 otherwise. Ported from the owner's
swift-invoice-pdf (Scripts/facturx-validate.py).
"""

import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

from saxonche import PySaxonProcessor

CACHE = Path(__file__).resolve().parent.parent / ".validator"
XSD = CACHE / "xsd" / "CrossIndustryInvoice_100pD16B.xsd"
SCH = CACHE / "schematron"
ISO = CACHE / "iso"
SVRL = "{http://purl.oclc.org/dsdl/svrl}"

PROFILES = {
    "urn:factur-x.eu:1p0:minimum": "FACTUR-X_MINIMUM.sch",
    "urn:factur-x.eu:1p0:basicwl": "FACTUR-X_BASIC-WL.sch",
    "urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic": "FACTUR-X_BASIC.sch",
    "urn:cen.eu:en16931:2017": "FACTUR-X_EN16931.sch",
}

COMPILED = {}


def compiled(proc, sch: Path):
    """Schematron to SVRL-producing XSLT, once per profile: include, expand, compile."""
    if sch not in COMPILED:
        source = str(sch)
        for stage in ["iso_dsdl_include.xsl", "iso_abstract_expand.xsl", "iso_svrl_for_xslt2.xsl"]:
            executable = proc.new_xslt30_processor().compile_stylesheet(stylesheet_file=str(ISO / stage))
            result = executable.transform_to_string(source_file=source)
            source = str(sch.parent / f"{sch.stem}.{stage}.xml")
            Path(source).write_text(result)
        COMPILED[sch] = proc.new_xslt30_processor().compile_stylesheet(stylesheet_file=source)
    return COMPILED[sch]


def profile_of(xml: Path) -> str:
    ram = "{urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100}"
    node = ET.parse(xml).getroot().find(f".//{ram}GuidelineSpecifiedDocumentContextParameter/{ram}ID")
    return "" if node is None or node.text is None else node.text.strip()


def validate(proc, xml: Path) -> int:
    profile = profile_of(xml)
    if profile not in PROFILES:
        print(f"FAIL {xml.name}: unknown profile {profile!r}")
        return 1

    problems = 0
    schema = subprocess.run(["xmllint", "--noout", "--schema", str(XSD), str(xml)], capture_output=True, text=True)
    if schema.returncode != 0:
        problems += 1
        print(f"FAIL {xml.name}: the CII D16B schema")
        for line in schema.stderr.strip().splitlines()[:15]:
            print(f"     {line}")

    svrl = ET.fromstring(compiled(proc, SCH / PROFILES[profile]).transform_to_string(source_file=str(xml)))
    fired = len(svrl.findall(f".//{SVRL}fired-rule"))
    for tag in ("failed-assert", "successful-report"):
        for node in svrl.iter(SVRL + tag):
            problems += 1
            text = " ".join("".join(node.itertext()).split())
            print(f"FAIL {xml.name}: [{node.get('id') or '-'}] {text[:200]}")

    if fired == 0:
        problems += 1
        print(f"FAIL {xml.name}: no rule fired, so the ruleset matched nothing")

    if problems == 0:
        print(f"PASS {xml.name}: {PROFILES[profile]}, {fired} rules")
    return problems


if __name__ == "__main__":
    total = 0
    with PySaxonProcessor(license=False) as proc:
        for path in sys.argv[1:]:
            total += validate(proc, Path(path))
    sys.exit(1 if total else 0)
