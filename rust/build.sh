#!/bin/bash

# HighPer Brotli Compressor Build Script
# Builds the Rust Brotli library for maximum performance

set -e

echo "🗜️  Building HighPer Brotli Compressor (Rust FFI)..."

# Build the Rust library
cargo build --release

# Copy header file to target directory
cp brotli.h target/release/

# Create symlink for easier access
ln -sf target/release/libbrotli_compressor.so libbrotli.so

echo "✅ HighPer Brotli Compressor build complete!"
echo "📁 Library: $(pwd)/target/release/libbrotli_compressor.so"
echo "📁 Header: $(pwd)/target/release/brotli.h"
echo "🔗 Symlink: $(pwd)/libbrotli.so"

# Display library info
echo ""
echo "📊 Library Information:"
ls -la target/release/libbrotli_compressor.so
echo ""
echo "🧪 Running tests..."
cargo test

echo ""
echo "🚀 Brotli Compressor ready for integration!"
echo "Expected performance: 3-5x faster than PHP brotli extension"
echo "Target: 100MB/s+ compression throughput"