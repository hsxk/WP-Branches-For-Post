from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
FONT_REG = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

BG = "#f6f7f7"
WHITE = "#ffffff"
TEXT = "#1e1e1e"
MUTED = "#646970"
BORDER = "#dcdcde"
BLUE = "#3858e9"
BLUE_DARK = "#2145e6"
YELLOW_BG = "#fcf9e8"
YELLOW_BORDER = "#dba617"
RED = "#b32d2e"
ADMIN = "#1d2327"

def font(size, bold=False):
    return ImageFont.truetype(FONT_BOLD if bold else FONT_REG, size)

def rounded(draw, box, radius=10, fill=WHITE, outline=None, width=1):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)

def label(draw, xy, value, size, fill=TEXT, bold=False, anchor=None):
    draw.text(xy, value, font=font(size, bold), fill=fill, anchor=anchor)

def wrapped(draw, value, max_width, size, bold=False):
    lines, current = [], ""
    for word in value.split():
        test = (current + " " + word).strip()
        if draw.textbbox((0, 0), test, font=font(size, bold))[2] <= max_width:
            current = test
        else:
            if current:
                lines.append(current)
            current = word
    if current:
        lines.append(current)
    return lines

def branch_icon(draw, x, y, scale=1.0, color=BLUE):
    radius = int(7 * scale)
    points = [
        (x, y),
        (x + 42 * scale, y),
        (x + 42 * scale, y + 35 * scale),
        (x + 78 * scale, y + 35 * scale),
    ]
    draw.line((x + radius, y, x + 42 * scale - radius, y), fill=color, width=max(2, int(4 * scale)))
    draw.line((x + 42 * scale, y + radius, x + 42 * scale, y + 35 * scale - radius), fill=color, width=max(2, int(4 * scale)))
    draw.line((x + 42 * scale + radius, y + 35 * scale, x + 78 * scale - radius, y + 35 * scale), fill=color, width=max(2, int(4 * scale)))
    for px, py in points:
        draw.ellipse((px - radius, py - radius, px + radius, py + radius), fill=WHITE, outline=color, width=max(2, int(4 * scale)))

def banner(width, height, target):
    image = Image.new("RGB", (width, height), "#f0f3ff")
    draw = ImageDraw.Draw(image)
    scale = width / 772
    draw.ellipse((int(width * .73), int(-height * .35), int(width * 1.08), int(height * .75)), fill="#dfe5ff")
    draw.ellipse((int(width * .82), int(height * .35), int(width * 1.12), int(height * 1.35)), fill="#e9ecff")
    rounded(draw, (int(34*scale), int(42*scale), int(136*scale), int(144*scale)), int(22*scale), fill=WHITE, outline="#c7d1ff", width=max(1, int(2*scale)))
    branch_icon(draw, int(52*scale), int(77*scale), .82*scale)
    label(draw, (int(165*scale), int(52*scale)), "WP Branches", int(31*scale), bold=True)
    label(draw, (int(165*scale), int(89*scale)), "For Post", int(31*scale), bold=True)
    label(draw, (int(165*scale), int(139*scale)), "Edit safely. Merge when ready.", int(15*scale), fill=MUTED)
    for value, cx in [("Draft branch", 165), ("Conflict guard", 292), ("Safe merge", 432)]:
        x = int(cx * scale)
        tw = draw.textbbox((0, 0), value, font=font(int(12*scale), True))[2]
        rounded(draw, (x, int(181*scale), x + tw + int(24*scale), int(211*scale)), int(14*scale), fill=WHITE, outline="#c7d1ff")
        label(draw, (x + int(12*scale), int(189*scale)), value, int(12*scale), fill=BLUE_DARK, bold=True)
    x, y, w, h = int(565*scale), int(52*scale), int(150*scale), int(145*scale)
    rounded(draw, (x, y, x+w, y+h), int(14*scale), fill=WHITE, outline="#c7d1ff", width=max(1, int(2*scale)))
    for i, ww in enumerate([95, 110, 72, 102]):
        yy = y + int((30 + i*23) * scale)
        draw.rounded_rectangle((x+int(18*scale), yy, x+int((18+ww)*scale), yy+int(8*scale)), radius=int(4*scale), fill="#d7dcf5")
    branch_icon(draw, x+int(32*scale), y+int(121*scale), .45*scale)
    image.save(target, optimize=True)

def editor_base(title):
    width, height = 1440, 900
    image = Image.new("RGB", (width, height), BG)
    draw = ImageDraw.Draw(image)
    draw.rectangle((0, 0, width, 32), fill=ADMIN)
    label(draw, (16, 7), "W", 15, fill=WHITE, bold=True)
    label(draw, (44, 8), "WP Branches For Post Demo", 12, fill="#f0f0f1")
    label(draw, (1320, 8), "Admin", 12, fill="#f0f0f1")
    draw.rectangle((0, 32, width, 84), fill=WHITE)
    draw.line((0, 84, width, 84), fill=BORDER)
    label(draw, (22, 51), "←", 22)
    rounded(draw, (70, 44, 112, 72), 6, fill="#f0f0f1")
    label(draw, (91, 58), "+", 18, anchor="mm", bold=True)
    label(draw, (127, 53), title, 15, bold=True)
    rounded(draw, (1215, 44, 1294, 72), 5, fill=WHITE, outline=BORDER)
    label(draw, (1254, 58), "Preview", 11, anchor="mm")
    rounded(draw, (1305, 44, 1408, 72), 5, fill=BLUE)
    label(draw, (1356, 58), "Save draft", 11, fill=WHITE, anchor="mm", bold=True)
    draw.rectangle((0, 84, 1040, height), fill="#f0f0f1")
    rounded(draw, (170, 125, 910, 830), 3, fill=WHITE)
    label(draw, (245, 178), title, 34, bold=True)
    draw.rectangle((245, 245, 815, 246), fill="#e0e0e0")
    label(draw, (245, 285), "This is the editable post content.", 17, fill=MUTED)
    label(draw, (245, 323), "The public original remains unchanged while this branch is edited.", 17, fill=MUTED)
    draw.rectangle((1040, 84, width, height), fill=WHITE)
    draw.line((1040, 84, 1040, height), fill=BORDER)
    label(draw, (1065, 108), "Post", 13, bold=True)
    label(draw, (1115, 108), "Block", 13, fill=MUTED)
    draw.line((1040, 138, width, 138), fill=BORDER)
    return image, draw

def panel_header(draw, y=165):
    label(draw, (1065, y), "POST BRANCH", 12, fill=MUTED, bold=True)
    draw.line((1065, y+28, 1415, y+28), fill=BORDER)
    return y + 50

def screenshots():
    for old in ROOT.glob("screenshot-*"):
        old.unlink()

    image, draw = editor_base("Quarterly update")
    y = panel_header(draw)
    for line in wrapped(draw, "Edit safely in a separate draft. The published post stays unchanged until you merge the branch.", 330, 14):
        label(draw, (1065, y), line, 14); y += 22
    y += 10
    rounded(draw, (1065, y, 1192, y+38), 5, fill=BLUE)
    label(draw, (1128, y+19), "Create branch", 13, fill=WHITE, anchor="mm", bold=True)
    y += 70
    label(draw, (1065, y), "Existing branches", 13, bold=True)
    label(draw, (1065, y+30), "No active branches", 13, fill=MUTED)
    image.save(ROOT / "screenshot-1.png", optimize=True)

    image, draw = editor_base("Quarterly update — branch")
    y = panel_header(draw)
    for line in wrapped(draw, "This draft is isolated from the public original.", 330, 14):
        label(draw, (1065, y), line, 14); y += 22
    label(draw, (1065, y+4), "Open original", 13, fill=BLUE, bold=True)
    y += 42
    label(draw, (1065, y), "Created by Editor", 12, fill=MUTED)
    y += 40
    rounded(draw, (1065, y, 1234, y+38), 5, fill=BLUE)
    label(draw, (1149, y+19), "Merge into original", 13, fill=WHITE, anchor="mm", bold=True)
    label(draw, (1065, y+52), "Discard branch", 13, fill=RED, bold=True)
    image.save(ROOT / "screenshot-2.png", optimize=True)

    image, draw = editor_base("Quarterly update — branch")
    y = panel_header(draw)
    for line in wrapped(draw, "This draft is isolated from the public original.", 330, 14):
        label(draw, (1065, y), line, 14); y += 22
    label(draw, (1065, y+4), "Open original", 13, fill=BLUE, bold=True)
    y += 48
    rounded(draw, (1065, y, 1410, y+132), 5, fill=YELLOW_BG, outline=YELLOW_BORDER)
    label(draw, (1082, y+16), "Original changed", 13, bold=True)
    yy = y + 43
    for line in wrapped(draw, "The original changed after this branch was created. A normal merge is blocked to prevent overwriting newer work.", 310, 12):
        label(draw, (1082, yy), line, 12); yy += 18
    y += 155
    rounded(draw, (1065, y, 1287, y+38), 5, fill=WHITE, outline=RED)
    label(draw, (1176, y+19), "Force merge after review", 12, fill=RED, anchor="mm", bold=True)
    label(draw, (1065, y+52), "Discard branch", 13, fill=RED, bold=True)
    image.save(ROOT / "screenshot-3.png", optimize=True)

    width, height = 1440, 900
    image = Image.new("RGB", (width, height), BG)
    draw = ImageDraw.Draw(image)
    draw.rectangle((0, 0, width, 32), fill=ADMIN)
    label(draw, (16, 7), "W", 15, fill=WHITE, bold=True)
    label(draw, (44, 8), "WP Branches For Post", 12, fill="#f0f0f1")
    draw.rectangle((0, 32, 180, height), fill="#23282d")
    for i, (value, active) in enumerate([("Dashboard", False), ("Posts", True), ("Media", False), ("Pages", False), ("Comments", False), ("Plugins", False)]):
        yy = 65 + i*44
        if active:
            draw.rectangle((0, yy-8, 180, yy+28), fill="#0073aa")
        label(draw, (24, yy), value, 14, fill=WHITE if active else "#c3c4c7", bold=active)
    label(draw, (215, 72), "Posts", 28, bold=True)
    rounded(draw, (305, 65, 392, 98), 5, fill=WHITE, outline=BLUE)
    label(draw, (348, 81), "Add New", 12, fill=BLUE, anchor="mm", bold=True)
    rounded(draw, (215, 130, 1370, 605), 2, fill=WHITE, outline=BORDER)
    columns = [215, 265, 765, 935, 1110, 1250]
    for i, value in enumerate(["", "Title", "Author", "Status", "Modified", "Branch"]):
        if value:
            label(draw, (columns[i]+12, 151), value, 12, bold=True)
    draw.line((215, 175, 1370, 175), fill=BORDER)
    rows = [
        ("Quarterly update", "Editor", "Published", "Today", "Create branch"),
        ("Quarterly update — branch", "Editor", "Draft", "2 min ago", "Branch of #128"),
        ("Product roadmap", "Admin", "Published", "Yesterday", "Create branch"),
    ]
    for index, row in enumerate(rows):
        yy = 205 + index*105
        draw.rectangle((215, yy-20, 1370, yy+70), fill=WHITE if index % 2 == 0 else "#fcfcfc")
        label(draw, (278, yy), row[0], 14, fill=BLUE, bold=True)
        label(draw, (777, yy), row[1], 13)
        label(draw, (947, yy), row[2], 13)
        label(draw, (1122, yy), row[3], 13)
        if "Branch of" in row[4]:
            rounded(draw, (1258, yy-7, 1355, yy+20), 12, fill="#f0f0f1", outline=BORDER)
            label(draw, (1306, yy+6), row[4], 11, anchor="mm", fill=MUTED)
            label(draw, (278, yy+28), "Edit  |  Merge branch  |  Trash", 12, fill=MUTED)
        else:
            label(draw, (1259, yy), row[4], 12, fill=BLUE, bold=True)
            label(draw, (278, yy+28), "Edit  |  Quick Edit  |  Trash  |  View", 12, fill=MUTED)
    image.save(ROOT / "screenshot-4.png", optimize=True)

def icon(size, target):
    image = Image.new("RGB", (size, size), "#eef1ff")
    draw = ImageDraw.Draw(image)
    pad = int(size * .12)
    rounded(draw, (pad, pad, size-pad, size-pad), int(size*.18), fill=WHITE, outline="#c7d1ff", width=max(2, size//64))
    branch_icon(draw, int(size*.27), int(size*.39), size/250.0)
    image.save(target, optimize=True, quality=92)

if __name__ == "__main__":
    banner(772, 250, ROOT / "banner-772x250.png")
    banner(1544, 500, ROOT / "banner-1544x500.png")
    icon(128, ROOT / "icon-128x128.jpg")
    icon(256, ROOT / "icon-256x256.jpg")
    screenshots()
