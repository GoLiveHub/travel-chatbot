<?php
/**
 * train-ml.php — Обучает ML-классификатор на 3000+ фразах и сохраняет модель.
 * Запуск: php train-ml.php
 * Выход: data/ml_model.php (сериализованная модель + метаданные)
 */
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use Phpml\Classification\NaiveBayes;
use Phpml\FeatureExtraction\TfIdfTransformer;
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;

$jsonPath = __DIR__ . '/../data/training_data.json';
$modelPath = __DIR__ . '/../data/ml_model.php';

if (!file_exists($jsonPath)) {
    fwrite(STDERR, "Error: $jsonPath not found. Run generate-ml-dataset.php first.\n");
    exit(1);
}

$json = json_decode(file_get_contents($jsonPath), true);
$samples = $json['samples'] ?? [];
$count = count($samples);

echo "Loaded $count samples\n";

// Normalize text
function normalize(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

// Extract corpus and labels
$corpus = [];
$labels = [];
foreach ($samples as $sample) {
    $corpus[] = normalize($sample['text']);
    $labels[] = $sample['intent'];
}

// Train vectorizer + TF-IDF + classifier
$vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
$tfidf = new TfIdfTransformer();
$classifier = new NaiveBayes();

echo "Fitting vectorizer...\n";
$vectorizer->fit($corpus);
$vectorizer->transform($corpus);

echo "Fitting TF-IDF...\n";
$tfidf->fit($corpus);
$tfidf->transform($corpus);

echo "Training NaiveBayes...\n";
$classifier->train($corpus, $labels);

echo "Training complete!\n";

// Count samples per intent
$intentCounts = array_count_values($labels);
arsort($intentCounts);
echo "\nIntent distribution:\n";
foreach ($intentCounts as $intent => $cnt) {
    echo "  $intent: $cnt\n";
}

// Save model as serialized PHP
$modelData = [
    'version' => 3,
    'trained_at' => date('c'),
    'sample_count' => $count,
    'intent_counts' => $intentCounts,
];

$serialized = base64_encode(serialize($classifier));
$vectorizerSer = base64_encode(serialize($vectorizer));
$tfidfSer = base64_encode(serialize($tfidf));

$phpCode = "<?php\n// Auto-generated ML model v3 — do not edit manually\n// Trained: {$modelData['trained_at']}\n// Samples: {$modelData['sample_count']}\n\nrequire_once __DIR__ . '/../vendor/autoload.php';\n\nreturn [\n"
    . "    'version' => {$modelData['version']},\n"
    . "    'sample_count' => {$modelData['sample_count']},\n"
    . "    'trained_at' => '{$modelData['trained_at']}',\n"
    . "    'classifier' => unserialize(base64_decode('" . $serialized . "')),\n"
    . "    'vectorizer' => unserialize(base64_decode('" . $vectorizerSer . "')),\n"
    . "    'tfidf' => unserialize(base64_decode('" . $tfidfSer . "')),\n"
    . "];\n";

file_put_contents($modelPath, $phpCode);
echo "\nModel saved to $modelPath (" . strlen($phpCode) . " bytes)\n";
