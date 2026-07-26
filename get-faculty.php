<?php
require 'db.php';

header('Content-Type: application/json');

// The static list of faculty names - now in one central place
$hardcodedFaculty = [
    "SHRI. S. R. SOLANKI", "SHRI C. D. PATEL", "SMT. J. N. ACHARYA", "SHRI P. J. JOSHI",
    "SMT. B. I. SAINI", "SHRI M. R. THAKKAR", "SHRI N. A. PATEL", "SHRI S. D. PRAJAPATI",
    "SHRI K. D. PRAJAPATI", "SHRI P. M. PRAJAPATI", "SHRI K. M. MADHU", "SHRI SHYJU RAJU",
    "SMT. N. J. PATEL", "SHRI D. R. DODIYA", "SHRI Y. R. PATEL", "SHRI M. C. THAKORE",
    "SMTA. M. MEVADA", "SMT P. R. SHARMA", "SHRI U. V. PATEL"
];

// Fetch dynamic faculty from the database
$stmt = $conn->prepare("SELECT full_name FROM users WHERE role = 'staff' ORDER BY full_name ASC");
$stmt->execute();
$result = $stmt->get_result();

$db_faculty = [];
while ($row = $result->fetch_assoc()) {
    $db_faculty[] = $row['full_name'];
}
$stmt->close();
$conn->close();

// Merge, remove duplicates, and sort
$all_faculty_names = array_unique(array_merge($db_faculty, $hardcodedFaculty));
sort($all_faculty_names, SORT_STRING | SORT_FLAG_CASE);

echo json_encode(array_values($all_faculty_names));
