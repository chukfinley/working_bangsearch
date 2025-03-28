<?php

// --- Configuration ---
// Show errors for debugging (remove or set to 0 in production)
ini_set("display_errors", 1);
error_reporting(E_ALL);

// *** Use GitHub URL for bangs.csv ***
$bangs_github_url =
    "https://raw.githubusercontent.com/chukfinley/working_bangsearch/refs/heads/main/bangs.csv";
$default_search_url = "https://duckduckgo.com/?q=%s";
$search_form_page = "search_form.html"; // HTML page to show if no query

// --- Functions ---

/**
 * Loads bang commands from the GitHub CSV URL using cURL.
 *
 * @param string $github_url URL to the raw CSV file on GitHub.
 * @return array Associative array mapping bang key to search URL, or empty array on failure.
 */
function loadBangs(string $github_url): array
{
    $bangMap = [];

    // Check if cURL is available
    if (!function_exists("curl_init")) {
        error_log("Error: cURL extension is not installed or enabled.");
        // Consider adding a fallback to a local file here if desired
        return [];
    }

    $ch = curl_init();
    if ($ch === false) {
        error_log("Error: Failed to initialize cURL.");
        return [];
    }

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $github_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return content as string
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout in seconds
    curl_setopt($ch, CURLOPT_USERAGENT, "BangSearch-Fetcher/1.0"); // Identify client
    curl_setopt($ch, CURLOPT_FAILONERROR, true); // Fail on HTTP codes >= 400

    // Execute request
    $csvText = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Check for cURL or HTTP errors
    if ($csvText === false || $httpCode !== 200) {
        error_log(
            "Error fetching bangs.csv from GitHub. HTTP Code: " .
                $httpCode .
                ". cURL Error: " .
                $error
        );
        // Consider adding a fallback to a local file here if desired
        return [];
    }

    // Process the fetched CSV text
    $lines = explode("\n", trim($csvText)); // Split into lines

    $is_header = true; // Flag to skip header row
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue; // Skip empty lines
        }

        // Basic header detection (case-insensitive)
        if (
            $is_header &&
            stripos($line, "key") !== false &&
            stripos($line, "search_url") !== false
        ) {
            $is_header = false;
            continue;
        }
        $is_header = false; // Assume first non-empty line after potential header is data

        $parts = explode(",", $line, 2); // Split into max 2 parts (key, rest_of_url)
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $searchUrl = trim($parts[1]);
            if (!empty($key) && !empty($searchUrl)) {
                $bangMap[$key] = $searchUrl;
            }
        }
    }

    if (empty($bangMap)) {
        error_log(
            "Warning: Bang map is empty after processing fetched CSV from GitHub."
        );
    }

    return $bangMap;
}

/**
 * Extracts a bang key if present at start/end of a token.
 *
 * @param string $token The word to check.
 * @return ?string The bang key without '!', or null if not a bang.
 */
function extractBangKey(string $token): ?string
{
    // Check for leading '!' (and ensure it's not just "!")
    if (strpos($token, "!") === 0 && strlen($token) > 1) {
        return substr($token, 1);
    }
    // Check for trailing '!' (and ensure it's not just "!")
    if (substr($token, -1) === "!" && strlen($token) > 1) {
        return substr($token, 0, -1);
    }
    return null;
}

/**
 * Processes the raw query to find the destination URL.
 *
 * @param string $rawQuery The user's search query.
 * @param array $bangMap The map of loaded bangs.
 * @param string $default_search_url The fallback search URL template.
 * @return ?string The final destination URL, or null if invalid/empty.
 */
function processQuery(
    string $rawQuery,
    array $bangMap,
    string $default_search_url
): ?string {
    $trimmedQuery = trim($rawQuery);
    if (empty($trimmedQuery)) {
        return null; // Return null for empty query
    }

    $words = preg_split("/\s+/", $trimmedQuery); // Split by one or more whitespace characters
    if (empty($words)) {
        return null; // Should not happen if trim check passed, but safety
    }

    $bangKey = null;
    $queryTerms = [];
    $searchUrlTemplate = null;

    // Check first word for bang
    $firstWord = $words[0];
    $possibleKey = extractBangKey($firstWord);
    if ($possibleKey !== null && isset($bangMap[$possibleKey])) {
        $bangKey = $possibleKey;
        $queryTerms = array_slice($words, 1); // Get words after the bang
    }
    // Check last word for bang (only if different from first and more than one word)
    elseif (count($words) > 1) {
        $lastWord = end($words); // Get the last element
        $possibleKey = extractBangKey($lastWord);
        // Ensure the key exists in the map
        if ($possibleKey !== null && isset($bangMap[$possibleKey])) {
            $bangKey = $possibleKey;
            $queryTerms = array_slice($words, 0, -1); // Get words before the bang
        }
    }

    // Determine search URL template
    if ($bangKey !== null) {
        // Bang found, use its template
        $searchUrlTemplate = $bangMap[$bangKey];
    } else {
        // No bang found, use default template and all words as query
        $searchUrlTemplate = $default_search_url;
        $queryTerms = $words;
    }

    // Handle case where bang is used but no query terms are provided (e.g., "!g")
    // Replace %s with empty string to go to the site's search page (usually)
    if ($bangKey !== null && empty($queryTerms)) {
        return str_replace("%s", "", $searchUrlTemplate);
    }

    // Handle case where no bang and no query terms (should be caught earlier, but safety)
    if (empty($queryTerms)) {
        return null; // Invalid state
    }

    // Construct the final URL
    $queryString = urlencode(implode(" ", $queryTerms)); // Join terms and URL-encode
    return str_replace("%s", $queryString, $searchUrlTemplate);
}

// --- Main Logic ---

// Check if a query parameter 'q' exists in the URL (e.g., from browser search bar)
if (isset($_GET["q"])) {
    $rawQuery = $_GET["q"];

    // Load the bang commands from the GitHub URL
    $bangMap = loadBangs($bangs_github_url);

    // Check if bangs loaded successfully
    if (empty($bangMap)) {
        // Critical error: Bangs couldn't be loaded. Log it and fall back.
        error_log(
            "Critical: Bang map is empty (failed to load from GitHub). Falling back to default search for query: " .
                $rawQuery
        );
        // Redirect to default search engine with the original query
        $destinationUrl = str_replace(
            "%s",
            urlencode($rawQuery),
            $default_search_url
        );
        // Send 302 Found (Temporary Redirect) header
        header("Location: " . $destinationUrl, true, 302);
        exit(); // Stop script execution
    }

    // Process the query using the loaded bangs to get the destination URL
    $destinationUrl = processQuery($rawQuery, $bangMap, $default_search_url);

    if ($destinationUrl !== null) {
        // If a valid URL was generated, redirect the browser
        header("Location: " . $destinationUrl, true, 302); // Send 302 Found redirect
        exit(); // IMPORTANT: Stop script execution after sending header
    } else {
        // Query was invalid (e.g., just "!" or empty after processing)
        // Option 1: Redirect to the search form page with an error indicator
        if (!empty($search_form_page) && file_exists($search_form_page)) {
            // Redirect to the form page, adding an error flag to the URL
            header(
                "Location: /" . $search_form_page . "?error=invalid_query",
                true,
                302
            );
            exit();
        }
        // Option 2: Show a simple error message if no form page exists
        http_response_code(400); // Set HTTP status to Bad Request
        echo "Invalid search query provided.";
        exit();
    }
} else {
    // No 'q' parameter in the URL - User likely visited the root domain directly.
    // Display the search form page.
    if (!empty($search_form_page) && file_exists($search_form_page)) {
        // Output the content of the search_form.html file
        readfile($search_form_page);
        exit();
    } else {
        // Fallback message if search_form.html doesn't exist
        echo "<h1>Bang Search</h1><p>Enter a query in your browser's search bar or use the form (if configured).</p><p>Example: ?q=!g+your+search</p>";
        exit();
    }
}

?>
