# Kompiliert eine Schematron-Datei (z. B. KoSIT XRechnung-CII-validation.sch) zu XSLT 2.0.
#   python3 compile-schematron.py <regeln.sch> <ausgabe.xsl> <pfad/zu/schematron/trunk/schematron/code>
import sys, os
from saxonche import PySaxonProcessor
sch, out = sys.argv[1], sys.argv[2]
# ISO-Schematron-Skeleton aus https://github.com/Schematron/schematron (trunk/schematron/code)
code = sys.argv[3].rstrip('/') + '/'
with PySaxonProcessor(license=False) as p:
    x = p.new_xslt30_processor()
    s1 = x.compile_stylesheet(stylesheet_file=code+'iso_dsdl_include.xsl').transform_to_string(source_file=sch)
    open(out+'.1', 'w').write(s1)
    s2 = x.compile_stylesheet(stylesheet_file=code+'iso_abstract_expand.xsl').transform_to_string(source_file=out+'.1')
    open(out+'.2', 'w').write(s2)
    s3 = x.compile_stylesheet(stylesheet_file=code+'iso_svrl_for_xslt2.xsl').transform_to_string(source_file=out+'.2')
    open(out, 'w').write(s3)
print('ok', os.path.getsize(out))
