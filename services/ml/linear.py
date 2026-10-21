"""Small regularized linear estimators with explicit, auditable numerical objectives."""
import numpy as np


class StandardScaler:
    def fit(self, x):
        self.mean_ = np.mean(x, axis=0)
        self.scale_ = np.std(x, axis=0)
        self.scale_ = np.where(self.scale_ < 1e-12, 1.0, self.scale_)
        return self

    def transform(self, x):
        return (x - self.mean_) / self.scale_


class LogisticRegression:
    """Minimize summed log loss + ||weights||²/(2*C), intercept unpenalized.

    Damped Newton steps use a backtracking line search. A tiny diagonal jitter
    protects the solve; singular/nonconvergent fits fail instead of activating.
    """
    def __init__(self, C=1.0, max_iter=200):
        self.C = C
        self.max_iter = max_iter

    def fit(self, x, y):
        y = np.asarray(y, dtype=float)
        design = np.column_stack([x, np.ones(len(x))])
        penalty = np.r_[np.full(x.shape[1], 1 / self.C), 0.0]
        weights = np.zeros(design.shape[1])

        def objective(w):
            z = design @ w
            return float(np.sum(np.logaddexp(0, z) - y * z) + .5 * np.sum(penalty * w * w))

        converged = False
        for _ in range(self.max_iter):
            z = design @ weights
            p = 1 / (1 + np.exp(-np.clip(z, -35, 35)))
            gradient = design.T @ (p - y) + penalty * weights
            if np.max(np.abs(gradient)) < 1e-7:
                converged = True
                break
            hessian = (design.T * (p * (1 - p))) @ design + np.diag(penalty + 1e-10)
            step = np.linalg.solve(hessian, gradient)
            before = objective(weights)
            length = 1.0
            while length > 1e-10:
                proposed = weights - length * step
                if objective(proposed) <= before - 1e-4 * length * float(gradient @ step):
                    break
                length *= .5
            if length <= 1e-10:
                if np.max(np.abs(gradient)) < 1e-5:
                    converged = True
                    break
                raise ValueError("Logistic optimizer did not find a descending step")
            weights = proposed
        if not converged or not np.all(np.isfinite(weights)):
            raise ValueError("Logistic optimizer did not converge")
        self.coef_ = weights[:-1].reshape(1, -1)
        self.intercept_ = np.array([weights[-1]])
        return self

    def decision_function(self, x):
        return x @ self.coef_[0] + self.intercept_[0]


class Ridge:
    """Minimize squared error + alpha*||weights||², intercept unpenalized."""
    def __init__(self, alpha=10):
        self.alpha = alpha

    def fit(self, x, y):
        y = np.asarray(y, dtype=float)
        design = np.column_stack([x, np.ones(len(x))])
        penalty = np.diag(np.r_[np.full(x.shape[1], self.alpha), 0.0])
        weights = np.linalg.solve(design.T @ design + penalty, design.T @ y)
        if not np.all(np.isfinite(weights)):
            raise ValueError("Nonfinite Ridge fit")
        self.coef_ = weights[:-1]
        self.intercept_ = float(weights[-1])
        return self
