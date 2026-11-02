"""Read PHPUnit JUnit timings without dependencies: python3 tests/Profiling/report.py result.xml."""
import collections
import json
import sys
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
cases = []
classes = collections.defaultdict(lambda: [0, 0.0])
for case in root.iter("testcase"):
    seconds = float(case.get("time", "0"))
    class_name = case.get("classname", "")
    cases.append({"test": class_name + "::" + case.get("name", ""),
                  "seconds": seconds, "assertions": int(case.get("assertions", "0"))})
    classes[class_name][0] += 1
    classes[class_name][1] += seconds
total = sum(case["seconds"] for case in cases)
print(json.dumps({
    "tests": len(cases), "assertions": sum(case["assertions"] for case in cases),
    "test_seconds": total, "failures": len(list(root.iter("failure"))),
    "errors": len(list(root.iter("error"))), "skipped": len(list(root.iter("skipped"))),
    "top20": [{**case, "percent_of_test_time": case["seconds"] / total * 100 if total else 0}
              for case in sorted(cases, key=lambda case: case["seconds"], reverse=True)[:20]],
    "classes": [{"class": name, "tests": cost[0], "seconds": cost[1],
                 "percent_of_test_time": cost[1] / total * 100 if total else 0}
                for name, cost in sorted(classes.items(), key=lambda item: item[1][1], reverse=True)],
}, indent=2))
