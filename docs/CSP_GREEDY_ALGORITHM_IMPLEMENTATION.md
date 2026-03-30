# CSP & Greedy Algorithm Implementation
## Intelligent Study Plan Generator

### Overview
The GradMap system now uses advanced algorithms to generate optimized, personalized study plans for students. This implementation combines **Constraint Satisfaction Problem (CSP)** and **Greedy Algorithm** approaches.

---

## Algorithm Architecture

### 1. Constraint Satisfaction Problem (CSP) Phase
**Purpose:** Validate hard constraints before course selection

**Constraints Enforced:**
- ✅ **Prerequisite Completion** - Students can only enroll in courses where all prerequisites have been completed with passing grades (≥3.0)
- ✅ **Course Availability** - Respects semester offerings (1st Sem, 2nd Sem, Mid Year)
- ✅ **Already Completed** - Filters out courses the student has already passed
- ✅ **Year Standing** - Considers progression requirements

**Implementation:**
```php
private function applyConstraints($target_semester = null) {
    // Filter courses based on:
    // 1. Not completed
    // 2. Prerequisites satisfied
    // 3. Semester availability
    return $available_courses;
}
```

### 2. Greedy Algorithm Phase
**Purpose:** Optimize course selection for maximum efficiency

**Optimization Factors:**
1. **Critical Path Priority (Weight: 10x)** - Courses that are prerequisites for many other courses get highest priority
2. **Unit Optimization (Weight: 2x)** - Prioritize higher unit courses to maximize progress per semester
3. **Year Level Progression (Weight: 40-10)** - Complete lower years first (1st > 2nd > 3rd > 4th)
4. **Semester Sequencing (Weight: 5)** - Prefer 1st semester courses before 2nd semester

**Implementation:**
```php
private function prioritizeCourses($courses) {
    foreach ($courses as $course_code => $course) {
        $score = 0;
        $score += $this->countDependentCourses($course_code) * 10; // Critical path
        $score += $course['units'] * 2; // Unit weight
        $score += $year_priority[$course['year']]; // Year progression
        $score += ($course['semester'] === '1st Sem') ? 5 : 0; // Semester priority
        $priority_scores[$course_code] = $score;
    }
    arsort($priority_scores); // Sort by highest score
    return $prioritized_courses;
}
```

### 3. Term-by-Term Planning
**Purpose:** Build realistic semester schedules

**Features:**
- Unit limit enforcement (max 21 units per term)
- Optimal load balancing (18-21 units target)
- Dynamic prerequisite tracking
- Automatic progression to next available semester

---

## Data Flow

```
Student Data → CSP Phase → Greedy Phase → Optimized Plan
     ↓              ↓            ↓              ↓
[Completed    [Filter by   [Prioritize   [Term-by-term
 Courses]      constraints]  courses]      schedule]
```

### Step-by-Step Process:

1. **Load Student Data**
   - Fetch completed courses from `student_checklists`
   - Filter for passing grades (≥3.0, exclude INC/DRP)

2. **Load Curriculum**
   - Get all courses from `checklist_bscs`
   - Parse prerequisite relationships
   - Build dependency graph

3. **Determine Current Position**
   - Analyze completed courses
   - Identify current year/semester
   - Calculate starting point

4. **Generate Plan** (Iterative)
   - For each upcoming semester:
     - Apply CSP constraints → get available courses
     - Apply Greedy prioritization → rank courses
     - Build term schedule (respect unit limits)
     - Mark selected courses as "planned"
     - Update prerequisites for next iteration
   - Continue until all courses planned

5. **Output Results**
   - Structured semester-by-semester plan
   - Progress statistics
   - Completion projections

---

## Key Features

### 🎯 Personalization
- Adapts to each student's unique progress
- Considers individual completion history
- No two students get identical plans (unless identical progress)

### 🔒 Constraint Validation
- Guarantees prerequisite satisfaction
- Prevents impossible course combinations
- Respects curriculum structure

### ⚡ Optimization
- Maximizes units per semester
- Prioritizes critical path courses
- Minimizes time to graduation
- Balances workload across terms

### 📊 Statistics & Insights
- Completion percentage
- Courses completed/remaining
- Units completed/remaining
- Progress visualization

---

## Technical Implementation

### Files Created/Modified

1. **`student/generate_study_plan.php`** (NEW)
   - Core algorithm implementation
   - `StudyPlanGenerator` class
   - CSP constraint solver
   - Greedy prioritization engine
   - 400+ lines of algorithm logic

2. **`student/study_plan.php`** (MODIFIED)
   - Integration of algorithm
   - Statistics dashboard
   - Visual enhancements
   - Algorithm explanation UI

### Database Schema Used

```sql
-- Student progress tracking
student_checklists (
    student_id VARCHAR(50),
    course_code VARCHAR(20),
    final_grade VARCHAR(10)
)

-- Curriculum with prerequisites
checklist_bscs (
    course_code VARCHAR(20) PRIMARY KEY,
    course_title VARCHAR(100),
    credit_unit_lec INT,
    credit_unit_lab INT,
    pre_requisite VARCHAR(50),
    year VARCHAR(20),
    semester VARCHAR(20)
)
```

---

## Algorithm Complexity

### Time Complexity
- **CSP Phase:** O(n) where n = number of courses
- **Greedy Phase:** O(n log n) due to sorting
- **Overall:** O(n log n) per semester
- **Total:** O(s × n log n) where s = number of semesters

### Space Complexity
- O(n) for course storage
- O(n) for prerequisite map
- O(n) for completed courses tracking

**Performance:** Efficiently handles curricula with 100+ courses

---

## Usage Examples

### Example 1: New Student (No Completed Courses)
```
Input: Student with 0 completed courses
Output: Complete 4-year plan starting from 1st Year, 1st Semester
Plan: All curriculum courses organized optimally
```

### Example 2: 2nd Year Student
```
Input: Student completed all 1st year courses
CSP: Filters courses where 1st year prerequisites met
Greedy: Prioritizes 2nd year critical path courses
Output: Optimized plan from 2nd year onward
```

### Example 3: Irregular Student
```
Input: Student with mixed completion (some 2nd year, missing 1st year)
CSP: Identifies available courses regardless of year level
Greedy: Prioritizes missing prerequisites first
Output: Customized catch-up plan
```

---

## Benefits

### For Students
✅ Clear roadmap to graduation
✅ Optimal course sequencing
✅ Prerequisite validation
✅ Progress tracking
✅ Time-to-graduation estimation

### For Advisers
✅ Automated planning reduces workload
✅ Consistent recommendation logic
✅ Easy to explain (algorithm-based)
✅ Quick generation for many students

### For Institution
✅ Data-driven academic planning
✅ Improved graduation rates
✅ Better resource allocation
✅ Student retention improvement

---

## Future Enhancements

### Potential Improvements
1. **Multi-objective Optimization**
   - Consider student preferences
   - Balance difficulty across semesters
   - Optimize for specific career paths

2. **Machine Learning Integration**
   - Predict course difficulty
   - Recommend based on past performance
   - Suggest elective choices

3. **Real-time Schedule Integration**
   - Consider actual class schedules
   - Avoid time conflicts
   - Optimize campus commute

4. **Collaborative Filtering**
   - Recommend based on similar students
   - Success rate predictions
   - Peer performance analysis

---

## Testing Scenarios

### Test Case 1: Empty History
- **Input:** New student, no courses completed
- **Expected:** Full 4-year curriculum plan
- **Validation:** All prerequisites properly sequenced

### Test Case 2: Partial Completion
- **Input:** Student completed 30 courses
- **Expected:** Plan only remaining courses
- **Validation:** No duplicate courses, valid prerequisites

### Test Case 3: Prerequisite Chain
- **Input:** Student needs MATH 1 → MATH 2 → MATH 3 → MATH 4
- **Expected:** Courses planned in correct sequence
- **Validation:** No skipping prerequisites

### Test Case 4: Multiple Prerequisites
- **Input:** Course requires 3 prerequisites
- **Expected:** Course only appears after all 3 are planned
- **Validation:** CSP constraint properly enforced

---

## Conclusion

This implementation successfully integrates academic algorithms into the GradMap system, providing students with intelligent, personalized study plans. The combination of CSP (for constraint validation) and Greedy Algorithm (for optimization) creates a robust, efficient system that adapts to each student's unique academic journey.

**Result:** Students receive optimal, achievable study plans that maximize their path to graduation while respecting all academic requirements.

---

**Implementation Date:** January 20, 2026
**Version:** 1.0
**Status:** ✅ Active and Integrated
