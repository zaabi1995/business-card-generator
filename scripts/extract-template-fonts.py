#!/usr/bin/env python3
"""Thin CLI wrapper, see scripts/extract_template_fonts.py for the impl."""
import sys
import os
sys.path.insert(0, os.path.dirname(__file__))
from extract_template_fonts import main
sys.exit(main())
