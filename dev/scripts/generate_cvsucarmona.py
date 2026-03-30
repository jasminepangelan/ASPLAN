import re
from collections import OrderedDict

# Read the file
with open(r'c:\xampp\htdocs\ASPLAN_v2\dev\checklist_of_programs', 'r', encoding='utf-8') as f:
    content = f.read()

# Program abbreviation mapping
program_abbr = {
    'Bachelor of Science in Industrial Technology': 'BSIndT',
    'Bachelor of Science in Computer Engineering': 'BSCpE',
    'Bachelor of Science in Information Technology': 'BSIT',
    'Bachelor of Science in Computer Science': 'BSCS',
    'Bachelor of Science in Hospitality Management': 'BSHM',
    'Bachelor of Science in Business Administration Major in Human Resource Management': 'BSBA-HRM',
    'Bachelor of Science in Business Administration Major in Marketing Management': 'BSBA-MM',
    'Bachelor of Secondary Education Major in English': 'BSEd-English',
    'Bachelor of Secondary Education Major in Science': 'BSEd-Science',
    'Bachelor of Secondary Education Major in Mathematics': 'BSEd-Math',
}

# Parse INSERT values - handle escaped single quotes in strings
# Pattern: (year, 'program', 'year_level', 'semester', 'course_code', 'course_title', lec, lab, hrs_lec, hrs_lab, 'pre_req')
pattern = r"\((\d{4}),\s*'((?:[^']|'')+)',\s*'((?:[^']|'')+)',\s*'((?:[^']|'')+)',\s*'((?:[^']|'')+)',\s*'((?:[^']|'')*)',\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*'((?:[^']|'')*)'\)"

courses = OrderedDict()

for match in re.finditer(pattern, content):
    year = match.group(1)
    program = match.group(2).replace("''", "'")
    year_level = match.group(3).replace("''", "'").strip()
    semester = match.group(4).replace("''", "'").strip()
    course_code = match.group(5).replace("''", "'").strip()
    course_title = match.group(6).replace("''", "'")
    lec = match.group(7)
    lab = match.group(8)
    hrs_lec = match.group(9)
    hrs_lab = match.group(10)
    pre_req = match.group(11).replace("''", "'").strip()
    
    key = f"{year}_{course_code}"
    abbr = program_abbr.get(program, program)
    
    if key not in courses:
        courses[key] = {
            'curriculumyear_coursecode': key,
            'programs': [abbr],
            'course_title': course_title,
            'year_level': year_level,
            'semester': semester,
            'credit_units_lec': lec,
            'credit_units_lab': lab,
            'lect_hrs_lec': hrs_lec,
            'lect_hrs_lab': hrs_lab,
            'pre_requisite': pre_req if pre_req else 'NONE',
        }
    else:
        if abbr not in courses[key]['programs']:
            courses[key]['programs'].append(abbr)

# Generate SQL
header = """-- CvSU Carmona Courses Database
-- This table contains unique courses with aggregated program listings
-- Connected to osas_db database

USE `osas_db`;

CREATE TABLE IF NOT EXISTS `cvsucarmona_courses` (
  `curriculumyear_coursecode` varchar(50) NOT NULL,
  `programs` text NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `year_level` varchar(50) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `credit_units_lec` int(2) DEFAULT 0,
  `credit_units_lab` int(2) DEFAULT 0,
  `lect_hrs_lec` int(2) DEFAULT 0,
  `lect_hrs_lab` int(2) DEFAULT 0,
  `pre_requisite` varchar(255) DEFAULT 'NONE',
  PRIMARY KEY (`curriculumyear_coursecode`),
  KEY `year_level` (`year_level`),
  KEY `semester` (`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert aggregated course data
-- Courses are grouped by curriculum_year and course_code, with all programs listed
-- Program Abbreviations: BSIndT, BSCpE, BSIT, BSCS, BSHM, BSBA-HRM, BSBA-MM, BSEd-English, BSEd-Science, BSEd-Math

INSERT INTO `cvsucarmona_courses` (`curriculumyear_coursecode`, `programs`, `course_title`, `year_level`, `semester`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
"""

lines = []
for key, c in courses.items():
    programs_str = ', '.join(c['programs'])
    # Escape single quotes for SQL
    title = c['course_title'].replace("'", "''")
    pre_req = c['pre_requisite'].replace("'", "''")
    line = f"('{c['curriculumyear_coursecode']}', '{programs_str}', '{title}', '{c['year_level']}', '{c['semester']}', {c['credit_units_lec']}, {c['credit_units_lab']}, {c['lect_hrs_lec']}, {c['lect_hrs_lab']}, '{pre_req}')"
    lines.append(line)

sql_content = header + ',\n'.join(lines) + ';\n'

# Write to file
with open(r'c:\xampp\htdocs\ASPLAN_v2\dev\cvsucarmona_courses.sql', 'w', encoding='utf-8') as f:
    f.write(sql_content)

print(f"Total unique courses (rows): {len(lines)}")
print("File written to: dev/cvsucarmona_courses.sql")

# Show some stats
program_counts = {}
for c in courses.values():
    for p in c['programs']:
        program_counts[p] = program_counts.get(p, 0) + 1

print("\nCourses per program:")
for p, count in sorted(program_counts.items()):
    print(f"  {p}: {count}")

# Show shared courses
shared = sum(1 for c in courses.values() if len(c['programs']) > 1)
print(f"\nShared courses (in 2+ programs): {shared}")
