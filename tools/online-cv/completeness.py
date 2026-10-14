"""
"Is every piece in place?" from a finish photo - the two classical approaches of
docs/features/online-competitions/README.md §5.8, for the bench (spikes.md S2):

- **Background holes** (check_holes): the empty mat photographed before the unwrapping is the background model. In
  the finish photo, pixels that still look like the mat (per channel inside the mat's own range a few pixels around,
  so a phone bumped by a few pixels changes nothing; after correcting a smooth light drift between the two photos)
  are mat. The puzzle is the largest region that is not mat, its outline the four corners of its convex hull; mat
  inside the outline = holes. Cheap, needs no reference, works for low-texture motifs - needs the contrasting mat.
- **Reference-grid matching** (check_grid): features (AKAZE, or ORB) of the reference motif and of the photo →
  RANSAC homography → the photo warped onto the reference → a grid of cells about the size of a piece compared one by
  one: a cell that shows the mat instead of the motif is a hole; a cell that is neither (a hand, glare, a different
  puzzle) is unknown. Counts the holes; needs a clean reference and texture to place it.
- combine(): complete only when both say complete; gaps when either finds gaps; otherwise unsure - "holes first,
  matching as the second opinion".

Every verdict: {"verdict": "complete" | "gaps" | "unsure", "confidence": 0-1, "gaps": [{"x", "y", "w", "h"}] (boxes
in the finish photo's pixels), "reason": "...", "details": {...}}. The measure that matters is a false "complete" (a
missing piece passed): every doubt becomes "unsure" - a person looks - never "complete".

check_photo() is what the CV worker (server.py, docs/features/online-competitions/README.md §5.8) answers with: the
checks the inputs allow - both combined, one alone, or none (unsure).

OpenCV + numpy only (requirements.txt). No I/O here: the bench's check.py reads files, its selftest.py makes synthetic
puzzles, server.py downloads photos - the one implementation for all three (tools/online-bench imports it from here).
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import cv2
import numpy as np

# Bump when a threshold or a rule changes - the app stores one verdict per media per version, so a re-check with a new
# version adds a row
VERSION = 1

COMPLETE = "complete"
GAPS = "gaps"
UNSURE = "unsure"


@dataclass
class HolesOptions:
    # The photos are compared at this width (px) - a 1000-piece puzzle keeps ~10 px per piece edge
    work_width: int = 1280
    # How far the camera may have moved between the two photos (px at work_width): the mat's range is taken over
    # this neighbourhood
    shake_px: int = 4
    # Lab distance (OpenCV 8-bit Lab) beyond the mat's local range that still counts as mat
    mat_distance: float = 14.0
    # A mat region inside the outline at least this share of a piece is a hole; between `doubt_area` and this: doubt
    hole_area: float = 0.35
    doubt_area: float = 0.12
    # The puzzle region must fill its outline at least this much (unfinished, covered → unsure)
    min_solidity: float = 0.93
    # The region's hull must overlap its four-corner outline at least this much (IoU; a hand sticking in → unsure)
    min_quad_fit: float = 0.95
    # Mat-like pixels scattered inside the puzzle (motif in the mat's colour) above this share → unsure
    max_scattered_mat: float = 0.03
    # A "hole" larger than this many pieces is no missing piece but a wrong outline (an arm, an unfinished part) → unsure
    max_hole_pieces: float = 4.0
    # More holes than this, or holes next to small mat-coloured spots: the motif shares the mat's colour → unsure
    max_holes: int = 4
    # The outline is shrunk by this share of a piece's edge before holes are looked for (seams, edge shadow)
    edge_margin: float = 0.25
    # Something not the mat outside the outline at least this share of a piece, both its sides at least `loose_min_side`
    # of a piece's edge (a sliver along the mat's edge from a bumped phone is no piece), is a loose piece: an unfinished
    # puzzle - a band missing along an edge leaves a smaller clean rectangle every rule above passes - or anything put on
    # the mat after its empty photo (the box, a phone) → unsure, never complete
    loose_area: float = 0.35
    loose_min_side: float = 0.3


@dataclass
class GridOptions:
    detector: str = "akaze"
    work_width: int = 1600
    reference_width: int = 1000
    ratio: float = 0.8
    ransac_px: float = 4.0
    min_inliers: int = 25
    min_inlier_ratio: float = 0.2
    # The inliers must spread over this share of the reference (a homography fitted in one corner is a guess elsewhere)
    min_inlier_spread: float = 0.25
    # More hole cells than this (at least, or this share of all cells): the reference does not sit on the puzzle
    max_hole_cells: int = 6
    max_hole_share: float = 0.01
    # Each cell is compared inside this inner share (the seams and a little misregistration stay out)
    cell_inner: float = 0.7
    # A cell is "the mat" when this share of its pixels lies within mat_distance of the mat colour
    mat_share: float = 0.45
    mat_distance: float = 22.0
    # A textured cell matches at this correlation (the best within ±search_px - the homography is never perfect);
    # a flat one when its mean colour is this close
    min_zncc: float = 0.5
    search_px: int = 3
    max_colour_distance: float = 18.0
    flat_std: float = 6.0
    # Unknown cells (neither motif nor mat) at most this many before the verdict is unsure
    max_unknown_cells: int = 0


@dataclass
class Verdict:
    verdict: str
    confidence: float
    reason: str
    gaps: list = field(default_factory=list)
    details: dict = field(default_factory=dict)

    def as_dict(self) -> dict:
        return {
            "verdict": self.verdict,
            "confidence": round(float(self.confidence), 3),
            "reason": self.reason,
            "gaps": self.gaps,
            "details": self.details,
        }


def _resize_to_width(image: np.ndarray, width: int) -> tuple[np.ndarray, float]:
    scale = width / image.shape[1]

    if abs(scale - 1.0) < 1e-6:
        return image, 1.0

    interpolation = cv2.INTER_AREA if scale < 1 else cv2.INTER_LINEAR

    return cv2.resize(image, (width, round(image.shape[0] * scale)), interpolation=interpolation), scale


def _box(points: np.ndarray, scale: float) -> dict:
    x, y, w, h = cv2.boundingRect(points.astype(np.float32))

    return {"x": round(x / scale), "y": round(y / scale), "w": round(w / scale), "h": round(h / scale)}


def _quad_from_contour(contour: np.ndarray) -> np.ndarray | None:
    """Four corners of a contour's convex hull (approxPolyDP with a growing tolerance), or None."""
    hull = cv2.convexHull(contour)
    perimeter = cv2.arcLength(hull, True)

    for tolerance in np.linspace(0.005, 0.08, 40):
        approx = cv2.approxPolyDP(hull, tolerance * perimeter, True)

        if len(approx) == 4:
            return approx.reshape(4, 2).astype(np.float32)

        if len(approx) < 4:
            return None

    return None


def _light_correction(finish: np.ndarray, mat: np.ndarray) -> tuple[np.ndarray, dict]:
    """
    The finish photo with the light of the mat photo. Where both photos show the mat, their per-channel ratio (on
    blurred images) is the light drift - auto-exposure, a lamp moved: a gain and a gradient. Those pixels are the ones
    whose ratio is the most common one (the mode of the histogram - the puzzle may cover more of the frame than the
    mat does, so not the median), the drift is fitted there by a plane in log space and divided out.
    """
    small_f = cv2.GaussianBlur(finish.astype(np.float32) + 1.0, (0, 0), 3)
    small_m = cv2.GaussianBlur(mat.astype(np.float32) + 1.0, (0, 0), 3)
    log_ratio = np.log(small_f) - np.log(small_m)
    height, width = log_ratio.shape[:2]
    ys, xs = np.mgrid[0:height, 0:width]
    xs = (xs / width - 0.5).astype(np.float32)
    ys = (ys / height - 0.5).astype(np.float32)
    # Mat in both photos: every channel near its most common ratio
    agree = np.ones((height, width), bool)
    modes = []

    for channel in range(3):
        values = log_ratio[:, :, channel]
        counts, edges = np.histogram(values, bins=120, range=(-1.2, 1.2))
        peak = int(np.argmax(counts))
        mode = float((edges[peak] + edges[peak + 1]) / 2)
        modes.append(mode)
        agree &= np.abs(values - mode) < 0.12

    corrected = finish.astype(np.float32)
    fits = []

    for channel in range(3):
        values = log_ratio[:, :, channel]

        if agree.sum() < 500:
            fits.append([modes[channel], 0.0, 0.0])
            corrected[:, :, channel] /= math.exp(modes[channel])
            continue

        step = max(1, int(agree.sum() // 20000))
        a = np.stack([np.ones(int(agree.sum())), xs[agree], ys[agree]], axis=1)[::step]
        b = values[agree][::step]
        coefficients, *_ = np.linalg.lstsq(a, b, rcond=None)
        fits.append([float(c) for c in coefficients])
        corrected[:, :, channel] /= np.exp(coefficients[0] + coefficients[1] * xs + coefficients[2] * ys)

    return np.clip(corrected, 0, 255).astype(np.uint8), {
        "lightFit": [[round(c, 3) for c in fit] for fit in fits],
        "matShare": round(float(agree.mean()), 3),
    }


def check_holes(mat_bgr: np.ndarray, finish_bgr: np.ndarray, pieces: int, options: HolesOptions | None = None) -> Verdict:
    """Background holes: the empty-mat photo and the finish photo (same camera, same place) → a verdict."""
    o = options or HolesOptions()

    if mat_bgr is None or finish_bgr is None or pieces <= 0:
        return Verdict(UNSURE, 0.0, "missing input")

    finish, scale = _resize_to_width(finish_bgr, o.work_width)
    mat = cv2.resize(mat_bgr, (finish.shape[1], finish.shape[0]), interpolation=cv2.INTER_AREA)
    finish, light = _light_correction(finish, mat)

    lab_f = cv2.cvtColor(finish, cv2.COLOR_BGR2LAB).astype(np.float32)
    lab_m = cv2.cvtColor(cv2.GaussianBlur(mat, (0, 0), 1.0), cv2.COLOR_BGR2LAB).astype(np.float32)
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * o.shake_px + 1, 2 * o.shake_px + 1))
    low = cv2.erode(lab_m, kernel)
    high = cv2.dilate(lab_m, kernel)
    # Distance to the mat's local range, per channel, then Euclidean
    outside = np.maximum(low - lab_f, 0) + np.maximum(lab_f - high, 0)
    distance = np.sqrt((outside ** 2).sum(axis=2))
    is_mat = (distance < o.mat_distance).astype(np.uint8)

    not_mat = 1 - is_mat
    not_mat = cv2.morphologyEx(not_mat, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    count, labels, stats, centroids = cv2.connectedComponentsWithStats(not_mat, connectivity=8)

    if count <= 1:
        return Verdict(UNSURE, 0.0, "no puzzle found - nothing differs from the empty mat", details=light)

    largest = 1 + int(np.argmax(stats[1:, cv2.CC_STAT_AREA]))
    outside = [(stats[index], centroids[index]) for index in range(1, count) if index != largest]
    region = (labels == largest).astype(np.uint8)
    contours, _ = cv2.findContours(region, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    contour = max(contours, key=cv2.contourArea)
    quad = _quad_from_contour(contour)
    frame_area = finish.shape[0] * finish.shape[1]

    if quad is None:
        return Verdict(UNSURE, 0.1, "the puzzle's outline is not a quadrilateral", details=light)

    quad_area = cv2.contourArea(quad)
    region_area = float(region.sum())

    if quad_area < 0.02 * frame_area:
        return Verdict(UNSURE, 0.1, "the puzzle is too small in the photo", details=light)

    # A puzzle is a quadrilateral: its region's hull must be one (a hand or loose pieces at its edge make it another
    # shape), and the region must fill it (an unfinished puzzle does not)
    hull = cv2.convexHull(contour).reshape(-1, 2).astype(np.float32)
    overlap, _ = cv2.intersectConvexConvex(hull, quad)
    hull_area = cv2.contourArea(hull)
    fit = overlap / max(1.0, hull_area + quad_area - overlap)
    solidity = region_area / quad_area

    if fit < o.min_quad_fit:
        return Verdict(UNSURE, 0.2, "the puzzle's outline is no clean quadrilateral (a hand, loose pieces at its edge)",
                       details={**light, "quadFit": round(fit, 3)})

    if solidity < o.min_solidity:
        return Verdict(UNSURE, 0.2, "the puzzle does not fill its outline (unfinished, or covered)",
                       details={**light, "quadFit": round(fit, 3), "solidity": round(solidity, 3)})

    piece_area = quad_area / pieces
    piece_edge = math.sqrt(piece_area)
    inside = np.zeros(region.shape, np.uint8)
    cv2.fillConvexPoly(inside, quad.astype(np.int32), 1)
    margin = max(1, int(round(o.edge_margin * piece_edge)))
    inside = cv2.erode(inside, np.ones((2 * margin + 1, 2 * margin + 1), np.uint8))

    holes_mask = (is_mat & inside).astype(np.uint8)
    holes_mask = cv2.morphologyEx(holes_mask, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    count, labels, stats, _ = cv2.connectedComponentsWithStats(holes_mask, connectivity=8)
    gaps = []
    gap_pixels = 0
    doubts = 0
    largest_share = 0.0

    for index in range(1, count):
        area = int(stats[index, cv2.CC_STAT_AREA])
        share = area / piece_area
        largest_share = max(largest_share, share)

        if share >= o.hole_area:
            points = np.column_stack(np.nonzero(labels == index))[:, ::-1]
            gaps.append({**_box(points, scale), "pieces": round(share, 2)})
            gap_pixels += area
        elif share >= o.doubt_area:
            doubts += 1

    # Mat-coloured pixels inside the puzzle that are no hole: the motif in the mat's colour
    scattered = float(int(holes_mask.sum()) - gap_pixels) / max(1.0, float(inside.sum()))
    # Loose pieces: what is not the mat outside the outline, as large as a part of a piece and not a sliver
    loose = sum(
        1 for component, centre in outside
        if component[cv2.CC_STAT_AREA] >= o.loose_area * piece_area
        and min(component[cv2.CC_STAT_WIDTH], component[cv2.CC_STAT_HEIGHT]) >= o.loose_min_side * piece_edge
        and cv2.pointPolygonTest(quad.reshape(-1, 1, 2), (float(centre[0]), float(centre[1])), False) < 0
    )
    details = {
        **light,
        "quad": [[round(float(x) / scale), round(float(y) / scale)] for x, y in quad],
        "pieceAreaPx": round(piece_area / scale ** 2),
        "quadFit": round(fit, 3),
        "solidity": round(solidity, 3),
        "largestHoleShare": round(largest_share, 3),
        "doubts": doubts,
        "scatteredMat": round(scattered, 4),
        "loose": loose,
    }

    if any(g["pieces"] > o.max_hole_pieces for g in gaps):
        return Verdict(UNSURE, 0.3, "a mat-coloured area larger than a few pieces - an arm, an unfinished part, a wrong outline?",
                       details=details)

    if scattered > o.max_scattered_mat or len(gaps) > o.max_holes or (gaps and doubts > 0):
        return Verdict(UNSURE, 0.3, "the motif shares the mat's colour - holes cannot be told from the picture", details=details)

    if gaps:
        return Verdict(GAPS, min(1.0, 0.5 + 0.5 * min(g["pieces"] for g in gaps)), f"{len(gaps)} hole(s) the colour of the mat",
                       gaps=gaps, details=details)

    if loose > 0:
        return Verdict(UNSURE, 0.3, f"{loose} thing(s) next to the puzzle - loose pieces of an unfinished puzzle, the box?",
                       details=details)

    if doubts > 0:
        return Verdict(UNSURE, 0.4, f"{doubts} small mat-coloured spot(s) inside the puzzle", details=details)

    confidence = max(0.0, min(1.0, 1.0 - largest_share / o.hole_area)) * min(1.0, (fit - o.min_quad_fit) / (1 - o.min_quad_fit) + 0.5)

    return Verdict(COMPLETE, confidence, "no mat inside the puzzle", details=details)


def _features(gray: np.ndarray, detector: str):
    if detector == "orb":
        engine = cv2.ORB_create(nfeatures=6000, scaleFactor=1.2, nlevels=8, fastThreshold=10)
    else:
        engine = cv2.AKAZE_create(threshold=0.0005)

    return engine.detectAndCompute(gray, None)


def grid_shape(pieces: int, aspect: float) -> tuple[int, int]:
    """Rows and columns of about `pieces` square-ish cells on a picture of width/height `aspect`."""
    columns = max(1, round(math.sqrt(pieces * aspect)))
    rows = max(1, round(pieces / columns))

    return rows, columns


def check_grid(reference_bgr: np.ndarray, finish_bgr: np.ndarray, rows: int, columns: int,
               mat_bgr: np.ndarray | None = None, options: GridOptions | None = None) -> Verdict:
    """Reference-grid matching: the clean motif, the finish photo, the grid of pieces → a verdict."""
    o = options or GridOptions()

    if reference_bgr is None or finish_bgr is None or rows <= 0 or columns <= 0:
        return Verdict(UNSURE, 0.0, "missing input")

    # About 30 px per piece edge at least: a 1000-piece reference is compared at ~1400 px
    reference, _ = _resize_to_width(reference_bgr, max(o.reference_width, int(30 * math.sqrt(rows * columns * reference_bgr.shape[1] / reference_bgr.shape[0]))))
    finish, scale = _resize_to_width(finish_bgr, min(o.work_width, finish_bgr.shape[1]))
    gray_r = cv2.cvtColor(reference, cv2.COLOR_BGR2GRAY)
    gray_f = cv2.cvtColor(finish, cv2.COLOR_BGR2GRAY)
    keypoints_r, descriptors_r = _features(gray_r, o.detector)
    keypoints_f, descriptors_f = _features(gray_f, o.detector)

    if descriptors_r is None or descriptors_f is None or len(keypoints_r) < o.min_inliers or len(keypoints_f) < o.min_inliers:
        return Verdict(UNSURE, 0.0, "too little texture to place the reference",
                       details={"keypoints": [len(keypoints_r or []), len(keypoints_f or [])]})

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    good = []

    for pair in matcher.knnMatch(descriptors_r, descriptors_f, k=2):
        if len(pair) == 2 and pair[0].distance < o.ratio * pair[1].distance:
            good.append(pair[0])

    if len(good) < o.min_inliers:
        return Verdict(UNSURE, 0.0, "the reference was not found in the photo", details={"matches": len(good)})

    source = np.float32([keypoints_r[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
    target = np.float32([keypoints_f[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)
    homography, inlier_mask = cv2.findHomography(source, target, cv2.RANSAC, o.ransac_px)

    if homography is None:
        return Verdict(UNSURE, 0.0, "no homography", details={"matches": len(good)})

    inliers = int(inlier_mask.sum())
    inlier_ratio = inliers / len(good)
    details = {"matches": len(good), "inliers": inliers, "inlierRatio": round(inlier_ratio, 3)}

    if inliers < o.min_inliers or inlier_ratio < o.min_inlier_ratio:
        return Verdict(UNSURE, 0.1, "the reference does not fit the photo well enough", details=details)

    height, width = reference.shape[:2]
    inlier_points = source[inlier_mask.ravel().astype(bool)].reshape(-1, 2)
    spread = cv2.contourArea(cv2.convexHull(inlier_points)) / float(width * height)
    details["inlierSpread"] = round(spread, 3)

    if spread < o.min_inlier_spread:
        return Verdict(UNSURE, 0.1, "the reference was placed from one part of the puzzle only (covered, glare?)", details=details)

    warped = cv2.warpPerspective(finish, np.linalg.inv(homography), (width, height), flags=cv2.INTER_LINEAR)
    lab_r = cv2.cvtColor(reference, cv2.COLOR_BGR2LAB).astype(np.float32)
    lab_w = cv2.cvtColor(warped, cv2.COLOR_BGR2LAB).astype(np.float32)

    mat_lab = _mat_colour(finish, homography, (width, height), mat_bgr)
    details["matLab"] = [round(float(v), 1) for v in mat_lab]

    # The photo's colours brought to the reference's (print vs. lamp light): per channel mean and spread - the mat's
    # colour (taken from a photo) with them
    for channel in range(3):
        mean_r, std_r = lab_r[:, :, channel].mean(), lab_r[:, :, channel].std() + 1e-3
        mean_w, std_w = lab_w[:, :, channel].mean(), lab_w[:, :, channel].std() + 1e-3
        lab_w[:, :, channel] = (lab_w[:, :, channel] - mean_w) * (std_r / std_w) + mean_r
        mat_lab[channel] = (mat_lab[channel] - mean_w) * (std_r / std_w) + mean_r

    # Lightness for the correlation, a little blurred (sensor noise, JPEG)
    light_r = cv2.GaussianBlur(lab_r[:, :, 0], (0, 0), 1.2)
    light_w = cv2.GaussianBlur(lab_w[:, :, 0], (0, 0), 1.2)
    cell_w = width / columns
    cell_h = height / rows
    pad_x = cell_w * (1 - o.cell_inner) / 2
    pad_y = cell_h * (1 - o.cell_inner) / 2
    holes = []
    unknown = 0
    ambiguous = 0
    worst_margin = 1.0

    for row in range(rows):
        for column in range(columns):
            x0, x1 = int(column * cell_w + pad_x), int((column + 1) * cell_w - pad_x)
            y0, y1 = int(row * cell_h + pad_y), int((row + 1) * cell_h - pad_y)

            if x1 - x0 < 3 or y1 - y0 < 3:
                continue

            cell_r = lab_r[y0:y1, x0:x1]
            cell_w_ = lab_w[y0:y1, x0:x1]
            mat_share = float((np.sqrt(((cell_w_ - mat_lab) ** 2).sum(axis=2)) < o.mat_distance).mean())
            reference_is_mat = float(np.sqrt(((cell_r.mean(axis=(0, 1)) - mat_lab) ** 2).sum())) < o.mat_distance
            colour_distance = float(np.sqrt(((cell_r.mean(axis=(0, 1)) - cell_w_.mean(axis=(0, 1))) ** 2).sum()))
            texture = float(cell_r[:, :, 0].std())

            if texture >= o.flat_std:
                template = light_r[y0:y1, x0:x1]
                sx0, sy0 = max(0, x0 - o.search_px), max(0, y0 - o.search_px)
                search = light_w[sy0:min(height, y1 + o.search_px), sx0:min(width, x1 + o.search_px)]
                zncc = float(cv2.matchTemplate(search, template, cv2.TM_CCOEFF_NORMED).max())
                similar = zncc >= o.min_zncc and colour_distance < 2 * o.max_colour_distance
                margin = (zncc - o.min_zncc) / (1 - o.min_zncc)
            else:
                similar = colour_distance < o.max_colour_distance
                margin = 1 - colour_distance / o.max_colour_distance

            if similar:
                worst_margin = min(worst_margin, max(0.0, margin))
                continue

            if mat_share >= o.mat_share:
                if reference_is_mat:
                    ambiguous += 1
                    continue

                corners = np.float32([[x0, y0], [x1, y0], [x1, y1], [x0, y1]]).reshape(-1, 1, 2)
                in_photo = cv2.perspectiveTransform(corners, homography).reshape(-1, 2)
                holes.append({**_box(in_photo, scale), "cell": [row, column]})
            else:
                unknown += 1

    details.update({"rows": rows, "columns": columns, "holeCells": len(holes), "unknownCells": unknown, "ambiguousCells": ambiguous})

    if len(holes) > max(o.max_hole_cells, o.max_hole_share * rows * columns):
        return Verdict(UNSURE, 0.2, f"{len(holes)} cells show the mat - the reference does not sit on the puzzle", details=details)

    if holes:
        return Verdict(GAPS, min(1.0, 0.5 + 0.1 * inliers / o.min_inliers), f"{len(holes)} cell(s) show the mat",
                       gaps=_merge_boxes(holes), details=details)

    if unknown > o.max_unknown_cells:
        return Verdict(UNSURE, 0.3, f"{unknown} cell(s) show neither the motif nor the mat (a hand, glare?)", details=details)

    if ambiguous > 0:
        return Verdict(UNSURE, 0.3, f"{ambiguous} cell(s) of the motif have the mat's colour", details=details)

    confidence = max(0.0, min(1.0, worst_margin)) * min(1.0, inlier_ratio / 0.5)

    return Verdict(COMPLETE, confidence, "every cell matches the reference", details=details)


def _mat_colour(finish: np.ndarray, homography: np.ndarray, size: tuple[int, int], mat_bgr: np.ndarray | None) -> np.ndarray:
    """The mat's Lab colour: the empty-mat photo's median, else a ring just outside the puzzle in the photo."""
    if mat_bgr is not None:
        lab = cv2.cvtColor(mat_bgr, cv2.COLOR_BGR2LAB).reshape(-1, 3)

        return np.median(lab, axis=0).astype(np.float32)

    width, height = size
    corners = np.float32([[0, 0], [width, 0], [width, height], [0, height]]).reshape(-1, 1, 2)
    outline = cv2.perspectiveTransform(corners, homography).reshape(-1, 2).astype(np.int32)
    inside = np.zeros(finish.shape[:2], np.uint8)
    cv2.fillConvexPoly(inside, outline, 1)
    ring = cv2.dilate(inside, np.ones((41, 41), np.uint8)) - cv2.dilate(inside, np.ones((11, 11), np.uint8))
    lab = cv2.cvtColor(finish, cv2.COLOR_BGR2LAB)[ring.astype(bool)]

    return np.median(lab, axis=0).astype(np.float32) if len(lab) else np.float32([0, 128, 128])


def _merge_boxes(cells: list[dict]) -> list[dict]:
    """Neighbouring hole cells as one box (a piece lying across two cells is one gap)."""
    remaining = [dict(cell) for cell in cells]
    merged = []

    while remaining:
        box = remaining.pop(0)
        grown = True

        while grown:
            grown = False

            for other in list(remaining):
                if abs(other["cell"][0] - box["cell"][0]) <= 1 and abs(other["cell"][1] - box["cell"][1]) <= 1:
                    x0, y0 = min(box["x"], other["x"]), min(box["y"], other["y"])
                    x1 = max(box["x"] + box["w"], other["x"] + other["w"])
                    y1 = max(box["y"] + box["h"], other["y"] + other["h"])
                    box.update({"x": x0, "y": y0, "w": x1 - x0, "h": y1 - y0})
                    remaining.remove(other)
                    grown = True

        merged.append({key: box[key] for key in ("x", "y", "w", "h")})

    return merged


def combine(holes: Verdict, grid: Verdict) -> Verdict:
    """Complete only when both say so; gaps when either finds them; else unsure."""
    if holes.verdict == GAPS or grid.verdict == GAPS:
        gaps = (holes.gaps if holes.verdict == GAPS else []) + (grid.gaps if grid.verdict == GAPS else [])

        return Verdict(GAPS, max(holes.confidence if holes.verdict == GAPS else 0, grid.confidence if grid.verdict == GAPS else 0),
                       "holes: " + holes.reason + " / grid: " + grid.reason, gaps=gaps)

    if holes.verdict == COMPLETE and grid.verdict == COMPLETE:
        return Verdict(COMPLETE, min(holes.confidence, grid.confidence), "both checks find every piece")

    return Verdict(UNSURE, 0.3, "holes: " + holes.reason + " / grid: " + grid.reason)


def _merge_overlapping(boxes: list[dict]) -> list[dict]:
    """Boxes that overlap (one holds the other's centre) as one box around both - the same hole seen by both checks"""
    merged: list[dict] = []

    for box in boxes:
        box = {key: box[key] for key in ("x", "y", "w", "h")}

        for other in merged:
            def holds(outer: dict, inner: dict) -> bool:
                cx, cy = inner["x"] + inner["w"] / 2, inner["y"] + inner["h"] / 2

                return outer["x"] <= cx <= outer["x"] + outer["w"] and outer["y"] <= cy <= outer["y"] + outer["h"]

            if holds(other, box) or holds(box, other):
                x0, y0 = min(other["x"], box["x"]), min(other["y"], box["y"])
                x1, y1 = max(other["x"] + other["w"], box["x"] + box["w"]), max(other["y"] + other["h"], box["y"] + box["h"])
                other.update({"x": x0, "y": y0, "w": x1 - x0, "h": y1 - y0})
                break
        else:
            merged.append(box)

    return merged


def check_photo(finish_bgr: np.ndarray, mat_bgr: np.ndarray | None = None, reference_bgr: np.ndarray | None = None,
                pieces: int | None = None) -> tuple[Verdict, str, dict]:
    """
    The checks the inputs allow, as the CV worker answers (README §5.8): background holes with the empty-mat photo,
    reference-grid matching with the reference image, both combined (complete only when both say so) - one alone when
    only its input is there. Without the piece count (a round of several puzzles) or without either input: unsure.

    Returns (verdict, method, details): method "holes+grid" | "holes" | "grid" | "none"; details = each check's own
    verdict (as_dict, with its details) or None when it did not run.
    """
    details: dict = {"holes": None, "grid": None}

    if finish_bgr is None:
        return Verdict(UNSURE, 0.0, "no finish photo"), "none", details

    if pieces is None or pieces <= 1:
        return Verdict(UNSURE, 0.0, "the piece count is not known - nothing to compare the holes with"), "none", details

    holes = check_holes(mat_bgr, finish_bgr, pieces) if mat_bgr is not None else None
    grid = None

    if reference_bgr is not None:
        rows, columns = grid_shape(pieces, reference_bgr.shape[1] / reference_bgr.shape[0])
        grid = check_grid(reference_bgr, finish_bgr, rows, columns, mat_bgr=mat_bgr)

    details = {
        "holes": holes.as_dict() if holes is not None else None,
        "grid": grid.as_dict() if grid is not None else None,
    }

    if holes is not None and grid is not None:
        both = combine(holes, grid)
        # Both checks found the same hole: one box for it (the review draws each box over the photo)
        both.gaps = _merge_overlapping(both.gaps)

        return both, "holes+grid", details

    if holes is not None:
        return holes, "holes", details

    if grid is not None:
        return grid, "grid", details

    return Verdict(UNSURE, 0.0, "no empty-mat photo and no reference image - nothing to check against"), "none", details
