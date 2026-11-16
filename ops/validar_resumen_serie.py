import re

def validate_summary(slug, name, brands, html_content):
    errors = []
    
    # 1. Check for Norma (prohibited when series belongs to other publishers; allowed if officially imported from Norma Editorial in the future)
    is_norma_official = any('norma' in str(b).lower() for b in (brands or []))
    if not is_norma_official and re.search(r'\bnorma\b', html_content, re.I):
        errors.append("Mentions 'Norma' when series is not from Norma Editorial!")
        
    # 2. Check word count
    clean_text = re.sub(r'<[^>]+>', ' ', html_content)
    words = clean_text.split()
    wc = len(words)
    if wc < 100 or wc > 200:
        errors.append(f"Word count out of range: {wc} words (expected 110-190)")
        
    # 3. Check paragraphs
    p_tags = re.findall(r'<p>.*?</p>', html_content, re.DOTALL)
    if len(p_tags) < 2 or len(p_tags) > 4:
        errors.append(f"Unexpected number of <p> tags: {len(p_tags)}")
        
    # 4. Check publisher brand mention
    if brands:
        expected_brand = brands[0]
        # Match variations, e.g. 'Editorial Ivrea', 'Ivrea Argentina', 'Panini Manga', 'Milky Way'
        brand_key = expected_brand.split()[0].lower() # e.g. ivrea, panini, milky, planeta, ovni, arechi
        if brand_key not in html_content.lower():
            errors.append(f"Brand '{expected_brand}' not found in content!")
            
    # 5. Check Chile shipping mention
    if 'chile' not in html_content.lower():
        errors.append("Does not mention 'Chile' for shipping trust!")
        
    # 6. Check for external links to publishers (must be canonical internal /serie/ or /marca/)
    if re.search(r'https?://(?:www\.)?(?:panini|editorialivrea|planetadelibros|milkywayediciones|arechiediciones|distritomanga|normaeditorial|eccediciones)\b', html_content, re.I):
        errors.append("Contains forbidden external links to publisher websites!")
        
    return errors, wc

if __name__ == "__main__":
    print("Validator ready.")
